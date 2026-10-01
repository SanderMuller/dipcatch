<?php declare(strict_types=1);

namespace App\Services\BolApi;

/** A product bol.com sells, with its best offer in the Netherlands when it has one. */
final readonly class BolProduct
{
    public function __construct(
        public string $ean,
        public string $title,
        public string $url,
        public ?string $price,
        public ?string $strikethroughPrice = null,
        public ?string $imageUrl = null,
        public ?string $deliveryDescription = null,
    ) {}
}
