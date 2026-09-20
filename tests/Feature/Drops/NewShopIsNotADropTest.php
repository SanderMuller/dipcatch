<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use Illuminate\Support\Facades\Notification;

/**
 * A drop is a price falling at shops we were already watching. Adding a shop
 * that happens to be cheaper moves `cheapest_price` too, and the reference
 * still describes the shops from before it existed — so the gap between them
 * read as a fall.
 *
 * One digest carried three of these in four alerts: a 227 g bag of Twix
 * reported as 34% off a 333 g bag, a 240 g box of fish fingers as 61% off an
 * 840 g box, both on the second the smaller shop was added.
 */
beforeEach(function (): void {
    Notification::fake();
});

function shopWithReading(Product $product, string $host, string $price): Shop
{
    $shop = Shop::factory()->for($product)->create([
        'url' => "https://{$host}/p/" . fake()->uuid(),
        'current_price' => $price,
        'currency' => 'EUR',
    ]);

    PriceCheck::factory()->for($shop)->create([
        'price' => $price,
        'status' => ScrapeStatus::Ok,
        'in_stock' => true,
    ]);

    return $shop;
}

it('does not call a newly added cheaper shop a price drop', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'drop_threshold_pct' => 10]);

    shopWithReading($product, 'jumbo.com', '4.19');
    $product->recomputeCheapestShop();

    // The user adds a second shop. It is cheaper because the bag is smaller.
    $newcomer = shopWithReading($product, 'dirk.nl', '2.75');
    $trigger = PriceCheck::query()->where('shop_id', $newcomer->id)->sole();

    $product->refresh()->recomputeCheapestShop($trigger->id);

    expect(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(0);

    Notification::assertNothingSent();
});

it('still reports a fall at a shop it was already watching', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'drop_threshold_pct' => 10]);

    $shop = shopWithReading($product, 'jumbo.com', '4.19');
    $product->recomputeCheapestShop();

    // Same shop, second reading, genuinely lower.
    $shop->forceFill(['current_price' => '2.75'])->save();
    $later = PriceCheck::factory()->for($shop)->create([
        'price' => '2.75',
        'status' => ScrapeStatus::Ok,
        'in_stock' => true,
    ]);

    $product->refresh()->recomputeCheapestShop($later->id);

    expect(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1);

    Notification::assertSentTo($user, PriceDropNotification::class);
});
