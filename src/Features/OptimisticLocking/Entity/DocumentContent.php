<?php

namespace App\Features\OptimisticLocking\Entity;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\AutoIncrement;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'documents')]
class DocumentContent
{
    #[PrimaryKey]
    #[AutoIncrement]
    public ?int $id = null;

    #[Property]
    public string $content = '';

    #[Version]
    public int $version_content = 0;
}
