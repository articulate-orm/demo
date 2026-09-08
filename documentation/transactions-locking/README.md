# Transactions and Locking

Use transactions and row-level locks when writes must be consistent across multiple queries.

**Runnable feature commands:** `app:orders:place`, `app:orders:deadlock`

## What This Covers

- Automatic transaction wrappers
- Manual transaction control
- Rollback behavior
- `SELECT ... FOR UPDATE` row locks
- Deadlock avoidance through deterministic lock ordering

## Transactional

```php
$entityManager->transactional(function (EntityManager $em) use ($entity) {
    $em->persist($entity);
    $em->flush();

    return $entity;
});
```

The wrapper commits when the callback returns and rolls back when the callback throws.

## Manual Control

- `beginTransaction()` starts a transaction.
- `commit()` flushes and commits.
- `rollback()` rolls back pending database work.

Manual control is useful when a command needs to demonstrate intermediate failure states or lock behavior.

## Locking

Use `lock()` on the query builder for `SELECT ... FOR UPDATE`:

```php
$stock = $entityManager
    ->createQueryBuilder(StockLock::class)
    ->where('product_id', $productId)
    ->lock()
    ->getSingleResult();
```

Locks require an active transaction.

The Orders feature locks stock rows before decrementing inventory:

```php
$stock = $entityManager
    ->createQueryBuilder(StockLock::class)
    ->where('product_id', $productId)
    ->lock()
    ->getSingleResult();
```

## Common Pitfalls

- Calling `lock()` outside an active transaction raises a transaction-required error.
- Acquire multiple locks in a deterministic order to reduce deadlock risk.
- Keep transaction callbacks small; long-running work should happen before or after the transaction where possible.

## Navigation

Previous: [Custom Types](../custom-types/README.md)  
Base: [Documentation Index](../README.md)  
Next: [Optimistic Locking](../optimistic-locking/README.md)
