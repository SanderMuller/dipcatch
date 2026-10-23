<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Enums\ProductCategory;

/**
 * The product half of a create. The alert is set in a later step, after the
 * product exists.
 */
final readonly class ProductDraft
{
    public function __construct(
        public string $title,
        public ?string $imageUrl = null,
        public ?ProductCategory $category = null,
    ) {}
}
