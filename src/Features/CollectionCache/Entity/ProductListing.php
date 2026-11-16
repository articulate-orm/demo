<?php

namespace App\Features\CollectionCache\Entity;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

/**
 * Read-side slice of the `products` table, a sibling of Catalog\Entity\Product.
 * Cached lists are read through this class and invalidated by writes made
 * through Product: both resolve to the same table + primary key.
 */
#[Entity(tableName: 'products')]
final class ProductListing
{
    #[PrimaryKey]
    public int $id;

    #[Property(maxLength: 64)]
    public string $sku;

    #[Property(name: 'product_name', maxLength: 160)]
    public string $name;

    #[Property(maxLength: 32)]
    public string $status;

    #[Property(nullable: true)]
    public ?int $categoryId = null;
}
