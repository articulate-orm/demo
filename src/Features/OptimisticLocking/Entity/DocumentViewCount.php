<?php

namespace App\Features\OptimisticLocking\Entity;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

/**
 * Never touches a column any other slice guards with #[Version] — a
 * legitimate unguarded write, no lost-update risk, articulate:validate
 * stays clean for it.
 */
#[Entity(tableName: 'documents')]
class DocumentViewCount
{
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(defaultValue: '0')]
    public int $view_count = 0;
}
