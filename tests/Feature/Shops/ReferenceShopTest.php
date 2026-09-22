<?php declare(strict_types=1);

use App\Actions\Shops\KeepShopAsLink;
use App\Billing\PlanLimitReached;
use App\Console\Commands\RecheckActiveShopsCommand;
use App\Enums\ShopKind;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * Finding which shops sell a thing is the slow part. When one refused to be
 * read that work was discarded, and the next session repeated it. A reference
 * shop is that research kept — a link, never a price.
 */
function keptLink(Product $product, string $url = 'https://www.bol.com/nl/nl/p/thing/9200000000000001/'): Shop
{
    return app(KeepShopAsLink::class)($product, $url, 'blocked');
}

test('a kept link holds no price and is marked as a link', function (): void {
    $shop = keptLink(Product::factory()->create(['currency' => 'EUR']));

    expect($shop->kind)->toBe(ShopKind::Reference)
        ->and($shop->isReference())->toBeTrue()
        ->and($shop->current_price)->toBeNull()
        ->and($shop->unreadable_reason)->toBe('blocked')
        // Never read, so no failure to its name. A count here would be judged
        // by `dead_after` and the row would switch itself off.
        ->and($shop->consecutive_failures)->toBe(0);
});

test('a kept link never reaches either answer', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);

    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1'])
        ->forceFill(['currency' => 'EUR', 'current_price' => '2.00', 'pack_quantity' => '100.00', 'pack_unit' => 'g'])->save();

    keptLink($product);
    $product->refresh()->recomputeCheapestShop();

    expect($product->refresh()->cheapestShop?->host)->toBe('ah.nl')
        ->and($product->bestValueShop()?->host)->toBe('ah.nl')
        ->and($product->lowestOutlayShop()?->host)->toBe('ah.nl');
});

test('a product whose only shop is a link has no price and still renders', function (): void {
    // The state a product is in while its research is ahead of its coverage.
    $product = Product::factory()->create(['currency' => 'EUR']);
    keptLink($product);

    $product->refresh()->recomputeCheapestShop();

    expect($product->refresh()->cheapest_price)->toBeNull()
        ->and($product->bestValueShop())->toBeNull()
        ->and($product->comparablePacks()->hasComparisonUnit())->toBeFalse();
});

test('a kept link is never sent to the recheck queue', function (): void {
    // Its page is known to refuse us. A check would spend a fetch to learn
    // nothing, then count the refusal against the row until it died.
    Queue::fake();

    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR', 'active' => true]);
    keptLink($product);

    $this->artisan(RecheckActiveShopsCommand::class)->assertSuccessful();

    Queue::assertNotPushed(CheckShopPrice::class);
});

test('a tracked shop on the same product is still rechecked', function (): void {
    // The exclusion is the kind, not the product.
    Queue::fake();

    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR', 'active' => true]);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1'])
        ->forceFill(['currency' => 'EUR', 'current_price' => '2.00', 'last_checked_at' => null])->save();
    keptLink($product);

    $this->artisan(RecheckActiveShopsCommand::class)->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, 1);
});

test('a kept link counts against the shop limit', function (): void {
    // It occupies a place on the product, shows wherever shops are listed,
    // and costs a fetch every time it is retried. Letting it in free would
    // also be the hard direction to reverse.
    config()->set('plans.free.max_shops_per_product', 1);

    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR']);
    keptLink($product);

    expect(fn (): Shop => keptLink($product, 'https://www.etos.nl/p/2'))
        ->toThrow(PlanLimitReached::class);
});
