<?php

namespace App\Features\OptimisticLocking\Entity;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'documents')]
class DocumentMetadata
{
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(maxLength: 120, defaultValue: '')]
    public string $title = '';

    #[Property(maxLength: 255, defaultValue: '')]
    public string $tags = '';

    #[Version]
    public int $version_metadata = 0;
}
