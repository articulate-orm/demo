<?php

namespace App\Features\Billing\Entity;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\AutoIncrement;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\VersionAware;

/**
 * A second bounded-context class mapping the same `invoices` table: a narrow
 * title/notes-only edit path.
 *
 * It declares class-level #[VersionAware(['version'])], so it BUMPS the shared
 * `version` column on every UPDATE (keeping a full-model writer's lost-update
 * detection honest) but never CHECKS it — a lightweight title edit shouldn't
 * take on conflict detection it can't reason about. The bounded-context safety
 * contract: no sibling silently drops out of the version bump.
 */
#[Entity(tableName: 'invoices')]
#[VersionAware(['version'])]
final class InvoiceTitleEdit
{
    #[PrimaryKey]
    #[AutoIncrement]
    public ?int $id = null;

    #[Property(name: 'title', maxLength: 160)]
    public string $title;

    #[Property(maxLength: 500, nullable: true)]
    public ?string $notes = null;
}
