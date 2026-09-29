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

    /**
     * The product photo, or a marked stand-in when no offer serves one.
     *
     * A generated product is filler — an invented path on a real host — so
     * there is no photo of it to serve and no honest way to invent one. The
     * stand-in names the product it stands in for rather than showing some
     * other thing's picture, which would be worse than an empty frame.
     */
    public function image(): string
    {
        foreach ($this->offers as $offer) {
            if ($offer->imageUrl !== null) {
                return $offer->imageUrl;
            }
        }

        return 'https://placehold.co/600x600/e4e4e7/52525b.png?text=' . rawurlencode($this->title);
    }
}
