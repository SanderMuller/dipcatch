<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Support\PackSize;

/**
 * One offer on a Klarna page: a shop that sells the product, at the price and
 * under the title Klarna lists for it. A lead, not a reading: the price is
 * Klarna's, and DipCatch reads the shop's own page before it suggests it.
 */
final readonly class ShopLead
{
    public function __construct(
        public string $shopName,
        /** Normalised, without `www.`, as `shops.host` stores it. */
        public string $host,
        public string $title,
        public string $price,
        public string $currency,
        /** True in stock, false out of stock, null when Klarna does not know. */
        public ?bool $inStock,
        /** Parsed from the title; null when the title states no size. */
        public ?PackSize $packSize,
    ) {}

    /**
     * @return array{shop: string, host: string, price: string, currency: string, title: string}
     */
    public function toArray(): array
    {
        return [
            'shop' => $this->shopName,
            'host' => $this->host,
            'price' => $this->price,
            'currency' => $this->currency,
            'title' => $this->title,
        ];
    }
}
