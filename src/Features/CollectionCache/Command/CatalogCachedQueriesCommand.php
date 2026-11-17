<?php

namespace App\Features\CollectionCache\Command;

use App\Features\Analytics\Diagnostics\CountingQueryLogger;
use App\Features\Catalog\Entity\Category;
use App\Features\Catalog\Entity\InventorySlot;
use App\Features\Catalog\Entity\Product;
use App\Features\CollectionCache\Entity\ProductListing;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:catalog:cached-queries', description: 'Collection (query-region) second-level cache: QueryBuilder::cacheable()')]
final class CatalogCachedQueriesCommand extends Command
{
    private const int TTL = 300;

    private ?CountingQueryLogger $readerLogger = null;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $suffix = bin2hex(random_bytes(4));
        $categoryId = random_int(1_000_000, 9_999_999);

        $pool = new ArrayAdapter();
        $writer = $this->createEntityManager($pool);
        $reader = $this->createEntityManager($pool, $this->readerLogger = new CountingQueryLogger());

        [$ids, $taxonomyId] = $this->seed($writer, $suffix, $categoryId);
        $writer->clear();

        try {
            $this->demonstrateWarmUp($io, $reader, $categoryId);
            $this->demonstrateSiblingInvalidation($io, $reader, $writer, $categoryId, $ids);
            $this->demonstrateTableGeneration($io, $reader, $writer, $categoryId, $ids);
            $this->demonstrateJoinInvalidation($io, $reader, $writer, $categoryId, $ids);
            $this->demonstrateSubqueryInvalidation($io, $reader, $writer, $categoryId, $ids);
            $this->demonstrateDependsOn($io, $reader, $writer, $categoryId, $ids);
            $this->demonstrateMappingTable($io, $reader, $writer, $taxonomyId, $ids);
            $this->demonstrateDeletion($io, $reader, $writer, $categoryId, $ids);
            $this->demonstrateInserts($io, $reader, $writer, $categoryId, $suffix);
            $this->demonstrateNoPool($io, $categoryId);
        } finally {
            $this->cleanUp($writer, $categoryId, $taxonomyId, $ids);
        }

        $io->success('Collection cache demo completed');

        return Command::SUCCESS;
    }

    private function demonstrateWarmUp(SymfonyStyle $io, EntityManager $reader, int $categoryId): void
    {
        $io->section('Scenario 1 — cold vs warm: the id-list is cached, not the rows');

        [$cold, $coldQueries] = $this->measure(fn () => $this->listing($reader, $categoryId)->getResult());
        $reader->clear();
        [$warm, $warmQueries] = $this->measure(fn () => $this->listing($reader, $categoryId)->getResult());
        [, $plainQueries] = $this->measure(fn () => $reader->createQueryBuilder(ProductListing::class)
            ->where('category_id', $categoryId)->orderBy('id', 'ASC')->getResult());

        $io->definitionList(
            ['cold cacheable() query' => count($cold) . " row(s), {$coldQueries} SQL query(s)"],
            ['warm cacheable() query, identity map cleared' => count($warm) . " row(s), {$warmQueries} SQL query(s)"],
            ['same query without cacheable()' => "{$plainQueries} SQL query(s)"],
        );
        $io->text('The warm read resolves each cached id through the per-entity second-level cache, so no row data is duplicated.');

        $this->expect($coldQueries > 0 && $warmQueries === 0 && $plainQueries > 0, 'warm cacheable() read must not touch the database');
    }

    /**
     * @param list<int> $ids
     */
    private function demonstrateSiblingInvalidation(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $categoryId, array $ids): void
    {
        $io->section('Scenario 2 — a write through the sibling class evicts the cached list');

        $this->listing($reader, $categoryId)->getResult();
        $reader->clear();
        [, $beforeQueries] = $this->measure(fn () => $this->listing($reader, $categoryId)->getResult());

        $product = $writer->find(Product::class, $ids[0]);
        $product->name = 'Renamed through Product';
        $writer->flush();
        $writer->clear();

        $reader->clear();
        [$after, $afterQueries] = $this->measure(fn () => $this->listing($reader, $categoryId)->getResult());
        $names = array_map(static fn (ProductListing $p): string => $p->name, $after);

        $io->definitionList(
            ['list read before the write' => "{$beforeQueries} SQL query(s) (cache hit)"],
            ['Product::$name updated by another EntityManager' => $product->name],
            ['list read after the write' => "{$afterQueries} SQL query(s), first row: {$names[0]}"],
        );
        $io->text('Cached under ProductListing, invalidated by a write through Product: both map the same products row.');

        $this->expect($beforeQueries === 0 && $afterQueries > 0 && $names[0] === 'Renamed through Product', 'sibling write must evict the list and serve fresh data');
    }

    /**
     * @param list<int> $ids
     */
    private function demonstrateTableGeneration(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $categoryId, array &$ids): void
    {
        $io->section('Scenario 3 — table generation: an update moving a row INTO the predicate invalidates');

        $outsider = $this->newProduct('OUTSIDER-' . bin2hex(random_bytes(3)), $categoryId + 1);
        $writer->persist($outsider);
        $writer->flush();
        $writer->clear();
        $ids[] = $outsider->id;
        $this->setStock($writer, $outsider->id, 5, create: true);

        [$before, $after, $afterQueries] = $this->readAroundWrite(
            $reader,
            fn () => $this->listing($reader, $categoryId),
            function () use ($writer, $outsider, $categoryId): void {
                $row = $writer->find(Product::class, $outsider->id);
                $row->categoryId = $categoryId;
                $writer->flush();
                $writer->clear();
            },
        );

        $io->definitionList(
            ['rows in cached list' => (string) count($before)],
            ['moved a product from another category into this one' => 'flush ok'],
            ['list read afterwards' => count($after) . " row(s), {$afterQueries} SQL query(s)"],
        );
        $io->text('Every flush bumps the generation of each table it writes. A cached id-list is keyed by its tables\' generations, so any write to products makes it miss, even for a row that was never in the list. Coarse, but rows entering the predicate are no longer missed.');

        $this->expect(count($after) === count($before) + 1 && $afterQueries > 0, 'update moving a row into the predicate must invalidate the list');
    }

    /**
     * @param list<int> $ids
     */
    private function demonstrateJoinInvalidation(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $categoryId, array $ids): void
    {
        $io->section('Scenario 4 — joined tables are dependencies: a stock write evicts the joined list');

        $this->listing($reader, $categoryId)->getResult();
        [$before, $after, $afterQueries] = $this->readAroundWrite(
            $reader,
            fn () => $this->inStockListing($reader, $categoryId),
            fn () => $this->setStock($writer, $ids[0], 0),
        );
        [, $plainQueries] = $this->measure(fn () => $this->listing($reader, $categoryId)->getResult());

        $io->definitionList(
            ['in-stock list (JOIN product_stock) before' => count($before) . ' row(s)'],
            ['InventorySlot::$stock set to 0 for one product' => 'flush ok'],
            ['in-stock list afterwards' => count($after) . " row(s), {$afterQueries} SQL query(s)"],
            ['plain list (no join) afterwards' => "{$plainQueries} SQL query(s) (still cached)"],
        );
        $io->text('join()/leftJoin()/rightJoin()/crossJoin() tables are folded into the key next to the root table. A query that does not read product_stock keeps its hit.');

        $this->expect(count($after) === count($before) - 1 && $afterQueries > 0 && $plainQueries === 0, 'joined-table write must evict only lists that join it');
    }

    /**
     * @param list<int> $ids
     */
    private function demonstrateSubqueryInvalidation(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $categoryId, array $ids): void
    {
        $io->section('Scenario 5 — subquery tables are dependencies too');

        $query = fn () => $reader->createQueryBuilder(ProductListing::class)
            ->where('category_id', $categoryId)
            ->whereIn('id', $reader->createQueryBuilder()->select('product_id')->from('product_stock')->where('stock', '>', 0))
            ->orderBy('id', 'ASC')
            ->cacheable(self::TTL);

        [$before, $after, $afterQueries] = $this->readAroundWrite($reader, $query, fn () => $this->setStock($writer, $ids[0], 5));

        $io->definitionList(
            ['whereIn(id, <product_stock subquery>) before' => count($before) . ' row(s)'],
            ['stock restocked for one product' => 'flush ok'],
            ['list afterwards' => count($after) . " row(s), {$afterQueries} SQL query(s)"],
        );
        $io->text('QueryBuilder subqueries passed to whereIn/whereNotIn/whereExists/orWhereExists contribute their FROM, joins and nested subqueries, recursively.');

        $this->expect(count($after) === count($before) + 1 && $afterQueries > 0, 'subquery-table write must evict the list');
    }

    /**
     * @param list<int> $ids
     */
    private function demonstrateDependsOn(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $categoryId, array $ids): void
    {
        $io->section('Scenario 6 — raw SQL is invisible: declare it with cacheable(dependsOn:)');

        $raw = fn (array $dependsOn) => fn () => $reader->createQueryBuilder(ProductListing::class)
            ->where('category_id', $categoryId)
            ->whereRaw('EXISTS (SELECT 1 FROM product_stock ps WHERE ps.product_id = products.id AND ps.stock > 0)')
            ->orderBy('id', 'ASC')
            ->cacheable(self::TTL, dependsOn: $dependsOn);

        $raw([])()->getResult();
        [$before, $declaredAfter, $declaredQueries] = $this->readAroundWrite(
            $reader,
            $raw(['product_stock']),
            fn () => $this->setStock($writer, $ids[0], 0),
        );
        [$undeclaredAfter, $undeclaredQueries] = $this->measure(fn () => $raw([])()->getResult());

        $io->definitionList(
            ['whereRaw(EXISTS ... product_stock) before' => count($before) . ' row(s)'],
            ['stock set to 0 for one product' => 'flush ok'],
            ['without dependsOn' => count($undeclaredAfter) . " row(s), {$undeclaredQueries} SQL query(s) (stale until TTL)"],
            ["with dependsOn: ['product_stock']" => count($declaredAfter) . " row(s), {$declaredQueries} SQL query(s)"],
        );

        $this->expect(
            count($undeclaredAfter) === count($before) && $undeclaredQueries === 0
                && count($declaredAfter) === count($before) - 1 && $declaredQueries > 0,
            'only the dependsOn query must notice a raw-SQL table write',
        );
    }

    /**
     * @param list<int> $ids
     */
    private function demonstrateMappingTable(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $taxonomyId, array $ids): void
    {
        $io->section('Scenario 7 — mapping (pivot) table writes from collection sync invalidate');

        $query = fn () => $reader->createQueryBuilder(ProductListing::class)
            ->join('categories_products cp', 'cp.products_id = products.id')
            ->where('cp.categories_id', $taxonomyId)
            ->orderBy('products.id', 'ASC')
            ->cacheable(self::TTL);

        [$before, $after, $afterQueries] = $this->readAroundWrite($reader, $query, function () use ($writer, $taxonomyId, $ids): void {
            $writer->find(Product::class, $ids[0])->categories->add($writer->find(Category::class, $taxonomyId));
            $writer->flush();
            $writer->clear();
        });

        $io->definitionList(
            ['products in category (JOIN categories_products) before' => count($before) . ' row(s)'],
            ['$product->categories->add($category)' => 'flush ok'],
            ['list afterwards' => count($after) . " row(s), {$afterQueries} SQL query(s)"],
        );
        $io->text('The unit of work bumps the mapping table\'s generation when it syncs a many-to-many collection.');

        $this->expect(count($after) === count($before) + 1 && $afterQueries > 0, 'pivot write must evict the list joining it');
    }

    /**
     * @param list<int> $ids
     */
    private function demonstrateDeletion(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $categoryId, array $ids): void
    {
        $io->section('Scenario 8 — deleting a cached row');

        $this->listing($reader, $categoryId)->getResult();
        $reader->clear();
        $before = count($this->listing($reader, $categoryId)->getResult());

        $victim = $writer->find(Product::class, $ids[1]);
        $writer->remove($victim);
        $writer->flush();
        $writer->clear();

        $reader->clear();
        [$after, $afterQueries] = $this->measure(fn () => $this->listing($reader, $categoryId)->getResult());

        $io->definitionList(
            ['rows in cached list before delete' => (string) $before],
            ['rows after delete' => count($after) . ", {$afterQueries} SQL query(s) (list was evicted, re-queried)"],
        );

        $this->expect(count($after) === $before - 1, 'deleted row must disappear from the list');
    }

    private function demonstrateInserts(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $categoryId, string $suffix): void
    {
        $io->section('Scenario 9 — inserts bump the same table generation');

        $this->listing($reader, $categoryId)->getResult();
        $reader->clear();
        $before = count($this->listing($reader, $categoryId)->getResult());

        $writer->persist($this->newProduct("NEW-{$suffix}", $categoryId));
        $writer->flush();
        $writer->clear();

        $reader->clear();
        [$after, $afterQueries] = $this->measure(fn () => $this->listing($reader, $categoryId)->getResult());
        [, $secondQueries] = $this->measure(fn () => $this->listing($reader, $categoryId)->getResult());

        $io->definitionList(
            ['rows before insert' => (string) $before],
            ['cacheable() read after insert' => count($after) . " row(s), {$afterQueries} SQL query(s)"],
            ['same read again' => "{$secondQueries} SQL query(s) (re-cached)"],
        );
        $io->text('Inserts bump the same table generation as updates and deletes. Lists of that table miss once and are rebuilt. Tables with heavy write traffic gain little from cacheable().');

        $this->expect(count($after) === $before + 1 && $afterQueries > 0 && $secondQueries === 0, 'insert must invalidate lists of its table, then re-cache');
    }

    private function demonstrateNoPool(SymfonyStyle $io, int $categoryId): void
    {
        $io->section('Scenario 10 — without a secondLevelCache pool cacheable() is a silent no-op');

        $logger = new CountingQueryLogger();
        $em = $this->createEntityManager(null, $logger);
        $this->listing($em, $categoryId)->getResult();
        $em->clear();
        $logger->reset();
        $this->listing($em, $categoryId)->getResult();

        $io->text("Second identical cacheable() query on an EntityManager without a pool: {$logger->count()} SQL query(s).");

        $this->expect($logger->count() > 0, 'cacheable() without a pool must always hit the database');
    }

    private function listing(EntityManager $em, int $categoryId): QueryBuilder
    {
        return $em->createQueryBuilder(ProductListing::class)
            ->where('category_id', $categoryId)
            ->orderBy('id', 'ASC')
            ->cacheable(self::TTL);
    }

    private function inStockListing(EntityManager $em, int $categoryId): QueryBuilder
    {
        return $em->createQueryBuilder(ProductListing::class)
            ->join('product_stock ps', 'ps.product_id = products.id')
            ->where('products.category_id', $categoryId)
            ->where('ps.stock', '>', 0)
            ->orderBy('products.id', 'ASC')
            ->cacheable(self::TTL);
    }

    /**
     * Warm the cache, run $write through the other EntityManager, re-read.
     *
     * @param callable(): QueryBuilder $query
     *
     * @return array{0: array, 1: array, 2: int} rows before, rows after, SQL queries of the re-read
     */
    private function readAroundWrite(EntityManager $reader, callable $query, callable $write): array
    {
        $before = $query()->getResult();
        $reader->clear();
        $write();
        $reader->clear();
        [$after, $queries] = $this->measure(fn () => $query()->getResult());

        return [$before, $after, $queries];
    }

    private function setStock(EntityManager $writer, int $productId, int $stock, bool $create = false): void
    {
        if ($create) {
            $slot = new InventorySlot();
            $slot->productId = $productId;
            $writer->persist($slot);
        } else {
            $slot = $writer->find(InventorySlot::class, $productId);
        }
        $slot->stock = $stock;
        $writer->flush();
        $writer->clear();
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    private function measure(callable $callback): array
    {
        $this->readerLogger?->reset();
        $result = $callback();

        return [$result, $this->readerLogger?->count() ?? 0];
    }

    /**
     * Three products in $categoryId, each with stock 5; the second one is also
     * linked through categories_products to a fresh category.
     *
     * @return array{0: list<int>, 1: int} product ids, taxonomy category id
     */
    private function seed(EntityManager $writer, string $suffix, int $categoryId): array
    {
        $taxonomy = new Category();
        $taxonomy->name = "Collection cache {$suffix}";
        $taxonomy->slug = "collection-cache-{$suffix}";
        $writer->persist($taxonomy);

        $products = [];
        foreach (['A', 'B', 'C'] as $letter) {
            $product = $this->newProduct("CC-{$suffix}-{$letter}", $categoryId);
            $writer->persist($product);
            $products[] = $product;
        }
        $products[1]->categories = [$taxonomy];
        $writer->flush();

        $ids = array_map(static fn (Product $p): int => $p->id, $products);
        foreach ($ids as $id) {
            $this->setStock($writer, $id, 5, create: true);
        }

        return [$ids, $taxonomy->id];
    }

    private function newProduct(string $sku, int $categoryId): Product
    {
        $product = new Product();
        $product->sku = $sku;
        $product->name = "Cached {$sku}";
        $product->slug = strtolower($sku);
        $product->status = 'active';
        $product->categoryId = $categoryId;
        $product->price = 9.99;

        return $product;
    }

    /**
     * @param list<int> $ids
     */
    private function cleanUp(EntityManager $writer, int $categoryId, int $taxonomyId, array $ids): void
    {
        $connection = $writer->getConnection();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $connection->executeQuery("DELETE FROM product_stock WHERE product_id IN ({$placeholders})", $ids);
        $connection->executeQuery('DELETE FROM categories_products WHERE categories_id = ?', [$taxonomyId]);
        $connection->executeQuery('DELETE FROM categories WHERE id = ?', [$taxonomyId]);
        $connection->executeQuery('DELETE FROM products WHERE category_id IN (?, ?)', [$categoryId, $categoryId + 1]);
    }

    private function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Collection cache demo expectation failed: {$message}.");
        }
    }

    private function createEntityManager(?ArrayAdapter $pool, ?CountingQueryLogger $logger = null): EntityManager
    {
        return new EntityManager(
            new Connection($this->env('DATABASE_DSN'), $this->env('DATABASE_USER'), $this->env('DATABASE_PASSWORD'), $logger),
            secondLevelCache: $pool,
        );
    }

    private function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("Missing required environment variable {$name}.");
        }

        return preg_replace_callback(
            '/\$\{([A-Z0-9_]+)\}/',
            fn (array $matches): string => $this->env($matches[1]),
            $value,
        );
    }
}
