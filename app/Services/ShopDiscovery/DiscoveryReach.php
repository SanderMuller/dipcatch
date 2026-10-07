<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;

/**
 * Whether web discovery can search for a product: searches and the AI check
 * are set up, and the product is priced in the currency of its owner's
 * country, the one the search runs in. A shop there in another currency
 * fails the page read anyway. Discovery also needs the owner's shop checks
 * on; a prompt may promise a search only where this holds.
 */
final readonly class DiscoveryReach
{
    public function __construct(private WebSearches $searches) {}

    public function covers(?User $owner, string $currency): bool
    {
        return $this->searches->enabled()
            && TypeSafeClient::configured()
            && strcasecmp($currency, (string) self::currencyFor($owner)) === 0;
    }

    public function coversProduct(?Product $product): bool
    {
        return $product instanceof Product && $this->covers($product->loadMissing('user')->user, $product->currency);
    }

    /** The currency discovery searches in for the owner. Null where no product can be priced in it. */
    public static function currencyFor(?User $owner): ?string
    {
        return ShoppersCountry::currency(ShoppersCountry::of($owner));
    }
}
