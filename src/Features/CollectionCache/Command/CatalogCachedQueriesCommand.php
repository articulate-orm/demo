<?php

namespace App\Features\CollectionCache\Command;

use App\Features\Analytics\Diagnostics\CountingQueryLogger;
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

        $ids = $this->seed($writer, $suffix, $categoryId);
        $writer->clear();

        try {
            $this->demonstrateWarmUp($io, $reader, $categoryId);
            $this->demonstrateSiblingInvalidation($io, $reader, $writer, $categoryId, $ids);
            $this->demonstratePreciseInvalidation($io, $reader, $writer, $categoryId);
            $this->demonstrateDeletion($io, $reader, $writer, $categoryId, $ids);
            $this->demonstrateInserts($io, $reader, $writer, $categoryId, $suffix);
            $this->demonstrateNoPool($io, $categoryId);
        } finally {
            $this->cleanUp($writer, $categoryId);
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

    private function demonstratePreciseInvalidation(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $categoryId): void
    {
        $io->section('Scenario 3 — exact invalidation: unrelated rows do not evict');

        $unrelatedSku = 'UNRELATED-' . bin2hex(random_bytes(3));
        $unrelated = $this->newProduct($unrelatedSku, $categoryId + 1);
        $writer->persist($unrelated);
        $writer->flush();
        $writer->clear();

        $this->listing($reader, $categoryId)->getResult();
        $reader->clear();

        $unrelated = $writer->find(Product::class, $unrelated->id);
        $unrelated->name = 'Changed unrelated row';
        $writer->flush();
        $writer->clear();

        [, $unrelatedQueries] = $this->measure(fn () => $this->listing($reader, $categoryId)->getResult());
        $io->definitionList(
            ['updated a row of the same table that is NOT in the cached list' => 'flush ok'],
            ['list read afterwards' => "{$unrelatedQueries} SQL query(s) (still cached)"],
        );
        $io->text('Invalidation uses a reverse index (table + id -> cached lists), not a per-table "last write" timestamp.');

        $writer->getConnection()->executeQuery('DELETE FROM products WHERE sku = ?', [$unrelatedSku]);

        $this->expect($unrelatedQueries === 0, 'unrelated update must not evict the cached list');
    }

    /**
     * @param list<int> $ids
     */
    private function demonstrateDeletion(SymfonyStyle $io, EntityManager $reader, EntityManager $writer, int $categoryId, array $ids): void
    {
        $io->section('Scenario 4 — deleting a cached row');

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
        $io->section('Scenario 5 — inserts: a per-table generation counter, coarser than updates');

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
        $io->text('A new row has no reverse-index entry yet, so every insert into the table bumps a per-table generation folded into the list keys. Lists of that table miss once and are rebuilt: correct, but coarse. Tables with heavy insert traffic gain little from cacheable().');

        $this->expect(count($after) === $before + 1 && $afterQueries > 0 && $secondQueries === 0, 'insert must invalidate lists of its table, then re-cache');
    }

    private function demonstrateNoPool(SymfonyStyle $io, int $categoryId): void
    {
        $io->section('Scenario 6 — without a secondLevelCache pool cacheable() is a silent no-op');

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
     * @return list<int>
     */
    private function seed(EntityManager $writer, string $suffix, int $categoryId): array
    {
        $products = [];
        foreach (['A', 'B', 'C'] as $letter) {
            $product = $this->newProduct("CC-{$suffix}-{$letter}", $categoryId);
            $writer->persist($product);
            $products[] = $product;
        }
        $writer->flush();

        return array_map(static fn (Product $p): int => $p->id, $products);
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

    private function cleanUp(EntityManager $writer, int $categoryId): void
    {
        $writer->getConnection()->executeQuery('DELETE FROM products WHERE category_id IN (?, ?)', [$categoryId, $categoryId + 1]);
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
