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

Second-level cache does not serve list/query paths such as `findBy()`, query-builder `getResult()`, or chunked batch reads.

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

- Second-level cache helps `find()` by primary key, not list queries.
- Result cache can return stale aggregate rows until its TTL expires.
- Current scalar and partial hydration caveats are tracked in [Known Limitations](../known-limitations/README.md).

## Navigation

Previous: [Optimistic Locking](../optimistic-locking/README.md)  
Base: [Documentation Index](../README.md)  
Next: [Known Limitations](../known-limitations/README.md)
