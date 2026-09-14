# Optimistic Locking

Detect lost updates when two contexts write the same row, without holding a database lock between the read and the write.

**Runnable feature command:** `app:billing:optimistic-lock`

## What This Covers

- `#[Version]` — a checked-and-bumped version column
- `#[VersionAware([...])]` — a bump-only sibling on a versioned table
- `OptimisticLockException` — a stale-version conflict surfaced as zero rows matched
- Recovery after a conflict without a poisoned unit of work
- The `articulate:validate` version-column coverage check

Optimistic locking is the clearest expression of Articulate's differentiator. A naive version-per-entity-class lock *breaks* under context-bounded entities: if only one sibling class mapping a table bumps and checks the version column, another sibling can silently overwrite changes undetected. Articulate makes the contract explicit and per-class, so every class mapping a versioned table must account for the version column — either by checking it (`#[Version]`) or by bumping it without checking (`#[VersionAware]`).

Contrast with [pessimistic locking](../transactions-locking/README.md): `SELECT ... FOR UPDATE` blocks other writers for the duration of a transaction, while optimistic locking never locks and instead detects the conflict at write time.

## The Two Bounded-Context Classes

The Billing feature maps one physical `invoices` table through two classes:

```php
#[Entity(tableName: 'invoices')]
final class Invoice
{
    #[PrimaryKey]
    #[AutoIncrement]
    public ?int $id = null;

    #[Property(name: 'title', maxLength: 160)]
    public string $title;

    // The canonical version column: bumped AND checked on every UPDATE.
    #[Property]
    #[Version]
    public int $version = 0;
}

#[Entity(tableName: 'invoices')]
#[VersionAware(['version'])]
final class InvoiceTitleEdit
{
    #[PrimaryKey]
    #[AutoIncrement]
    public ?int $id = null;

    // Narrow edit path: bumps the shared version column but never checks it.
    #[Property(name: 'title', maxLength: 160)]
    public string $title;
}
```

- `Invoice` carries `#[Version]`. Every UPDATE through this class runs `version = version + 1` **and** guards the write with `WHERE version = ?`.
- `InvoiceTitleEdit` declares class-level `#[VersionAware(['version'])]`. It bumps the shared `version` column on UPDATE so a full-model writer's check still fires, but a lightweight title edit does not take on lost-update detection it can't reason about, so it never checks the version itself.

## Happy Path

A fresh insert starts at `version = 0`; the next UPDATE guards on the version it read and bumps the row to `1`:

```php
$invoice = new Invoice();
$invoice->number = 'INV-1001';
$invoice->title = 'Initial invoice';
$em->persist($invoice);
$em->flush();               // version = 0 after INSERT

$invoice->amount = 150.0;
$em->persist($invoice);
$em->flush();               // UPDATE ... WHERE version = 0 → row is now version = 1
```

The in-memory `#[Version]` property is bumped to match the committed row.

## Conflict

When a concurrent writer moves the row on first, the stale flush guards on a version the row no longer holds, so the UPDATE matches zero rows and Articulate throws:

```php
$invoice = $em->find(Invoice::class, $id);          // reads version = 1

// A separate EntityManager loads, mutates, and commits first → row goes to version = 2.

$invoice->amount = 175.0;
$em->persist($invoice);

try {
    $em->flush();                                   // UPDATE ... WHERE version = 1 matches 0 rows
} catch (OptimisticLockException $e) {
    // stale-version conflict detected
}
```

`OptimisticLockException` does not distinguish a stale version from a deleted row — both are "zero rows matched." Telling them apart would need an extra `SELECT` the exception is meant to avoid.

## Recovery

A failed flush does not poison in-memory state: the in-memory `#[Version]` is not bumped past its pre-flush value, and there is no "manager is closed" state to reset. Re-`find()` the current row and flush again:

```php
$em->clear();
$fresh = $em->find(Invoice::class, $id);            // reads the current version
$fresh->amount = 200.0;
$em->persist($fresh);
$em->flush();                                       // succeeds against the up-to-date version
```

## Bump-Only Sibling

Editing through the `#[VersionAware]` sibling bumps the shared column without checking it, keeping a checking sibling's lost-update detection honest:

```php
$titleEdit = $em->find(InvoiceTitleEdit::class, $id);
$titleEdit->title = 'Retitled by the narrow edit path';
$em->persist($titleEdit);
$em->flush();                                       // shared version bumps; no WHERE version = ? guard
```

The next writer through `Invoice` will now see its own read as stale unless it reloaded after this edit — exactly the safety the bump provides.

## Validate Coverage (CI-critical)

There is **no runtime enforcement** that every class mapping a versioned table accounts for the version column. A class that maps `invoices` with neither `#[Version]` nor `#[VersionAware]` silently drops out of lost-update detection until `articulate:validate` catches it:

```
Class "App\Features\Billing\Entity\InvoiceUntracked" does not account for version column "version" on table "invoices".
```

Run `articulate:validate` in CI so a new bounded-context class can't quietly opt out of the version contract. When a table has more than one distinct `#[Version]` column across its classes, `validate` reports it at info level.

## Common Pitfalls

- A `#[Version]` property must be typed `int`.
- Do **not** write the same row through two different `#[Version]`-checking classes in one flush — the first UPDATE bumps the shared column and the second conflicts with itself. Use a `#[VersionAware]` sibling for the secondary write path instead.
- `OptimisticLockException` cannot tell a stale version from a deleted row; treat both as "the row moved on."
- Adding a class that maps a versioned table without declaring `#[Version]` or `#[VersionAware]` compiles and runs — only `articulate:validate` surfaces the gap.

## Navigation

Previous: [Transactions and Locking](../transactions-locking/README.md)  
Base: [Documentation Index](../README.md)  
Next: [Performance](../performance/README.md)
