# Performance

Understand the runtime behavior that matters once examples move beyond small CRUD flows.

**Runnable feature commands:** `app:analytics:report`, `app:analytics:batch`, `app:import:run`

## What This Covers

- Identity map behavior
- Result cache
- Second-level cache
- Query logging
- Partial and scalar hydration
- Batch iteration

## Identity Map

`EntityManager` keeps an in-memory identity map. Loading the same entity class and primary key twice returns the same PHP object instance until the manager is cleared.

This avoids duplicate objects for the same row, but it also means long-running imports should clear the manager periodically.

## Result Cache

```php
$qb->enableResultCache($lifetime, $cacheId);
```

Result cache stores query results. Locked queries are not cached.

## Second-Level Cache

```php
$em = new EntityManager($connection, secondLevelCache: $pool);
```

Second-level cache stores raw entity row data for `find()` lookups by entity class and primary key. It survives `EntityManager::clear()`, unlike the identity map.

When a flush updates, deletes, or soft-deletes an entity, Articulate evicts cache entries for every mapped entity class that shares the same table and primary key. This keeps same-row projections consistent after writes.

Second-level cache serves `find()` by primary key. List queries are cached only when you opt in, see [Collection Cache](#collection-cache).

## Query Logging

Implement `QueryLoggerInterface` to profile query count, SQL text, and cache effects. The analytics demo uses query logging to show result-cache hit and miss behavior.

## Partial Hydration

Use `PartialHydrator` or `ScalarHydrator` when full entity hydration is unnecessary. This is useful for reporting paths, aggregate output, and read-only projections.

## Batch Iteration

Avoid loading very large datasets with a single `getResult()` call. Process rows in bounded batches and call `$entityManager->clear()` between batches when the identity map would otherwise grow too large.

```php
$batch = $entityManager
    ->createQueryBuilder(OrderSnapshot::class)
    ->orderBy('placed_at', 'ASC')
    ->limit($chunkSize)
    ->offset($offset)
    ->getResult();

$entityManager->clear();
```

Source: [AnalyticsBatchCommand](../../src/Features/Analytics/Command/AnalyticsBatchCommand.php)

## Common Pitfalls

- Second-level cache helps `find()` by primary key; list queries need `cacheable()`.
- Result cache can return stale aggregate rows until its TTL expires.
- Current scalar and partial hydration caveats are tracked in [Known Limitations](../known-limitations/README.md).

## Collection Cache

```php
$products = $em->createQueryBuilder(ProductListing::class)
    ->where('category_id', 12)
    ->orderBy('id', 'ASC')
    ->cacheable(300)   // TTL in seconds; omit to use secondLevelCacheTtl
    ->getResult();
```

`cacheable()` caches the ordered list of primary keys a query matched, not the rows. Each id is resolved through the identity map or the per-entity second-level cache, so row data is stored once. It needs a `secondLevelCache` pool; without one it is a silent no-op, as it is for `lock()` queries, aggregates, custom hydrators and composite primary keys.

Invalidation is per table. Every table has a **generation** counter; each `flush()` bumps it once for every table it writes (insert, update, merged update, delete, soft delete, and mapping-table rows synced from many-to-many collections). A cached id-list is keyed by the generations of every table it reads, so any write to one of them makes it miss:

- **Root table:** any write evicts, including an update that moves a row into or out of the `WHERE`. A write through a sibling class on the same table (for example `Product` and `ProductListing`) evicts lists cached under the other class.
- **Joined tables:** `join()`, `leftJoin()`, `rightJoin()`, `crossJoin()` tables are dependencies. Writing `product_stock` evicts a list that joins it; a list that does not read `product_stock` keeps its hit.
- **Subqueries:** a `QueryBuilder` subquery passed to `whereIn()`, `whereNotIn()`, `whereExists()` or `orWhereExists()` adds its tables, recursively.
- **Mapping tables:** `$product->categories->add($category)` bumps `categories_products`, so a list joining it is evicted.
- **Raw SQL:** tables reached only through `whereRaw()` or a raw join condition are not detected. Declare them:

```php
->whereRaw('EXISTS (SELECT 1 FROM product_stock ps WHERE ps.product_id = products.id AND ps.stock > 0)')
->cacheable(300, dependsOn: ['product_stock'])
```

- **Not detected:** bulk `QueryBuilder::update()/delete()` and raw SQL writes. These stay stale until the TTL.

The trade-off is hit rate: a list joined to a frequently written table misses after each of those writes. Generations live in the shared pool and are bumped after `flush()` commits, so invalidation is shared across `EntityManager` instances.

Run `app:catalog:cached-queries` to see each case with SQL query counts. Source: [`src/Features/CollectionCache/`](../../src/Features/CollectionCache).

## Navigation

Previous: [Optimistic Locking](../optimistic-locking/README.md)  
Base: [Documentation Index](../README.md)  
Next: [Known Limitations](../known-limitations/README.md)
