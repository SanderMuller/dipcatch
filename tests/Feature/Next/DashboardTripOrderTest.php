<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\DashboardDigest;

/**
 * Two shops with the same number of best buys kept swapping places between
 * visits, with the order of whichever products were rechecked last. The
 * dashboard visibly jumped.
 */
it('orders shops with equal counts the same way on every visit, whatever was rechecked last', function (): void {
    $user = User::factory()->create();

    foreach (['zooplus.nl', 'ah.nl', 'jumbo.com'] as $host) {
        foreach (['A', 'B'] as $n) {
            $product = Product::factory()->for($user)->create(['title' => "{$host} {$n}", 'currency' => 'EUR']);
            // One deal per shop, so each one qualifies as a trip.
            Shop::factory()->for($product)->create(['url' => "https://{$host}/p/{$n}", 'current_price' => '1.00', 'currency' => 'EUR', 'promotion_ends_at' => $n === 'A' ? now()->addDays(2) : null]);
            $product->refresh()->recomputeCheapestShop();
        }
    }

    $first = array_column(DashboardDigest::forUser($user)->trips, 'host');

    // A recheck touches updated_at, which decides the order products are read in.
    Product::query()->where('title', 'zooplus.nl A')->first()?->touch();

    expect($first)->toBe(['ah.nl', 'jumbo.com', 'zooplus.nl'])
        ->and(array_column(DashboardDigest::forUser($user)->trips, 'host'))->toBe($first);
});
