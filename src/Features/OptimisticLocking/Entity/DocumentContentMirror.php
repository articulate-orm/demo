<?php

namespace App\Features\OptimisticLocking\Entity;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

/**
 * Guards the same 'content' column as DocumentContent, but with its own
 * distinct #[Version] column — a "rival counters" misconfiguration.
 * articulate:validate flags the overlap; nothing stops it at runtime.
 */
#[Entity(tableName: 'documents')]
class DocumentContentMirror
{
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $content = '';

    #[Version]
    public int $version_content_alt = 0;
}
