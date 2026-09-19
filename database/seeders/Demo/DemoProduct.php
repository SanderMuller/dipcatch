<?php declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\CategorySource;
use App\Enums\ProductCategory;

/**
 * One product in the demo catalog, with the offers it is tracked at.
 */
final readonly class DemoProduct
{
    /**
     * @param  list<DemoOffer>  $offers
     * @param  string|null  $drop  `today`, `recent` or `week` — when a price drop fired
     * @param  int|null  $historyDays  Days of price history, or null for the seeder default
     * @param  int|null  $ageDays  How long ago the product was added, or null for the seeder default
     */
    public function __construct(
        public string $title,
        public array $offers,
        public ?float $unitPriceTarget = null,
        public ?string $shareSlug = null,
        public bool $active = true,
        public ?string $drop = null,
        public ?int $historyDays = null,
        public ?int $ageDays = null,
        public ?ProductCategory $category = null,
        public CategorySource $categorySource = CategorySource::User,
    ) {}
}
