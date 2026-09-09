<?php declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * One product in the demo catalog, with the offers it is tracked at.
 */
final readonly class DemoProduct
{
    /**
     * @param  list<DemoOffer>  $offers
     * @param  string|null  $drop  `today`, `recent` or `week` — when a price drop fired
     */
    public function __construct(
        public string $title,
        public array $offers,
        public ?float $unitPriceTarget = null,
        public ?string $shareSlug = null,
        public bool $active = true,
        public ?string $drop = null,
    ) {}
}
