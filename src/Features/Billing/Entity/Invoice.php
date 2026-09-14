<?php

namespace App\Features\Billing\Entity;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\AutoIncrement;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

/**
 * Full write model for the `invoices` table.
 *
 * Carries the canonical #[Version] column: every UPDATE issued through this
 * class bumps `version = version + 1` and guards the write with
 * `WHERE version = ?`, so a concurrent writer that already moved the row on
 * is detected as a lost update (OptimisticLockException).
 */
#[Entity(tableName: 'invoices')]
final class Invoice
{
    #[PrimaryKey]
    #[AutoIncrement]
    public ?int $id = null;

    #[Property(maxLength: 32)]
    public string $number;

    #[Property(name: 'title', maxLength: 160)]
    public string $title;

    #[Property(maxLength: 500, nullable: true)]
    public ?string $notes = null;

    #[Property]
    public float $amount = 0.0;

    #[Property(maxLength: 32)]
    public string $status = 'draft';

    #[Property]
    #[Version]
    public int $version = 0;
}
