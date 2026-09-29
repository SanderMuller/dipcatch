<?php declare(strict_types=1);

namespace App\Services\Suggestions;

/**
 * One suggested shop for a product: the dataset row that matched, rendered
 * into everything a surface needs. `price` is the dataset's daily regular
 * price — never a live price — so a surface must label it as such.
 */
final readonly class ShopSuggestion
{
    public function __construct(
        public string $chain,
        public string $chainLabel,
        public string $externalId,
        public string $name,
        public ?string $size,
        public string $price,
        public string $url,
        public float $score,
        public bool $trackable,
        /** Jev confirmed the row sells the same product and pack. */
        public bool $checked = false,
    ) {}
}
