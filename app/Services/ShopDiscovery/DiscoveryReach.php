<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Models\Product;
use App\Services\TypeSafe\TypeSafeClient;

/**
 * Whether web discovery can search for a product, whatever the owner:
 * searches and the AI check are set up, and the currency is EUR. Discovery
 * also needs the owner's shop checks on. A prompt may promise a search only
 * where this holds.
 */
final readonly class DiscoveryReach
{
    public function __construct(private WebSearches $searches) {}

    public function covers(string $currency): bool
    {
        return $this->searches->enabled()
            && TypeSafeClient::configured()
            && strcasecmp($currency, 'EUR') === 0;
    }

    public function coversProduct(?Product $product): bool
    {
        return $product instanceof Product && $this->covers($product->currency);
    }
}
