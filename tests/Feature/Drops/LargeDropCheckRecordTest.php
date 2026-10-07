<?php declare(strict_types=1);

use App\Charts\PriceChangeAction;
use App\Charts\PriceChangeLog;
use App\Enums\LargeDropCheckOutcome;
use App\Jobs\CheckShopPrice;
use App\Models\LargeDropCheck;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * The record the price changes list reads: a large drop DipCatch asked a
 * second reading about, and what that reading said.
 */
beforeEach(function (): void {
    Notification::fake();
    Queue::fake();

    config()->set('dipcatch.drops.confirm_above_pct', 40);
});

/** A product at 100.00 on one shop, with history so a reference exists. */
function recordedDropShop(string $host = 'drop.test'): Shop
{
    $product = Product::factory()->for(User::factory()->create())->create([
        'currency' => 'EUR',
        'drop_threshold_pct' => '5.00',
        'drop_threshold_abs' => '1.00',
    ]);

    $shop = Shop::factory()->for($product)->create([
        'url' => "https://{$host}/p/1",
        'currency' => 'EUR',
        'current_price' => '100.00',
        'current_in_stock' => true,
    ]);

    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '100.00'])->save();

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '100.00',
        'started_at' => now()->subDays(10),
        'ended_at' => null,
    ]);

    return $shop->refresh();
}

/** Serve the given prices in turn, a null as a server error, and run one check per price. */
function readPrices(Shop $shop, ?string ...$prices): void
{
    $responses = array_map(static fn (?string $price) => $price === null
        ? Http::response('', 500)
        : Http::response(withJsonLd(json_encode([
            '@type' => 'Product',
            'name' => 'Dry shampoo',
            'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
        ], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']), $prices);

    Http::fake([
        "https://{$shop->host}/robots.txt" => Http::response('', 404),
        "https://{$shop->host}/p/1" => Http::sequence($responses),
    ]);

    // Handled in place: the queue is faked so the confirmation dispatch stays
    // queued, and a faked queue would swallow `dispatch_sync` too.
    foreach (array_keys($prices) as $ignored) {
        app()->call(new CheckShopPrice($shop->refresh(), manual: true)->handle(...));
    }
}

test('a first large reading records an open check on that reading', function (): void {
    $shop = recordedDropShop();

    readPrices($shop, '40.00');

    $check = LargeDropCheck::query()->sole();

    expect($check->shop_id)->toBe($shop->id)
        ->and($check->product_id)->toBe($shop->product_id)
        ->and($check->price)->toBe('40.00')
        ->and($check->outcome)->toBeNull()
        ->and($check->price_check_id)->toBe((int) PriceCheck::query()->sole()->id);
});

test('a second reading at the low price confirms the check and alerts', function (): void {
    $shop = recordedDropShop();

    readPrices($shop, '40.00', '40.00');

    expect(LargeDropCheck::query()->sole()->outcome)->toBe(LargeDropCheckOutcome::Confirmed)
        ->and(PriceDropEvent::count())->toBe(1);
});

test('a second reading back at the normal price rejects the check and alerts nothing', function (): void {
    $shop = recordedDropShop();

    readPrices($shop, '40.00', '99.00');

    $check = LargeDropCheck::query()->sole();

    expect($check->outcome)->toBe(LargeDropCheckOutcome::Rejected)
        ->and($check->resolved_at)->not->toBeNull()
        ->and(PriceDropEvent::count())->toBe(0);
});

test('an out-of-stock second reading rejects the check', function (): void {
    $shop = recordedDropShop();
    readPrices($shop, '40.00');
    $reading = PriceCheck::factory()->for($shop)->create(['price' => '40.00', 'in_stock' => false]);

    LargeDropCheck::answerWith($reading);

    expect(LargeDropCheck::query()->sole())
        ->outcome->toBe(LargeDropCheckOutcome::Rejected)
        ->resolved_by_price_check_id->toBe((int) $reading->id);
});

test('the shop repeating the low price confirms the check after another shop became cheaper', function (): void {
    $shop = recordedDropShop();
    $open = LargeDropCheck::factory()->create(['shop_id' => $shop->id, 'product_id' => $shop->product_id, 'price' => '40.00']);
    Shop::factory()->for($shop->product()->sole())->create(['url' => 'https://rival.test/p/1', 'currency' => 'EUR', 'current_price' => '30.00', 'current_in_stock' => true]);
    $shop->product()->sole()->recomputeCheapestShop();

    readPrices($shop, '40.00');

    expect($open->refresh()->outcome)->toBe(LargeDropCheckOutcome::Confirmed);
});

test('a failed second reading leaves the check open', function (): void {
    $shop = recordedDropShop();

    readPrices($shop, '40.00', null);

    expect(LargeDropCheck::query()->sole()->outcome)->toBeNull();
});

test('a dataset shop alerts on one reading and records no check', function (): void {
    $shop = recordedDropShop('ah.nl');
    $check = PriceCheck::factory()->for($shop)->create(['price' => '40.00']);
    $shop->update(['current_price' => '40.00']);

    $shop->product()->sole()->recomputeCheapestShop((int) $check->id);

    expect(LargeDropCheck::count())->toBe(0)
        ->and(PriceDropEvent::count())->toBe(1);
});

test('a reading at another shop leaves the open check alone', function (): void {
    $shop = recordedDropShop();
    $open = LargeDropCheck::factory()->create(['shop_id' => $shop->id, 'product_id' => $shop->product_id]);
    $rival = Shop::factory()->for($shop->product()->sole())->create(['url' => 'https://rival.test/p/1', 'currency' => 'EUR', 'current_price' => '120.00']);

    readPrices($rival, '120.00');

    expect($open->refresh()->outcome)->toBeNull();
});

test('the prune command removes checks older than a year', function (): void {
    $shop = recordedDropShop();
    $old = LargeDropCheck::factory()->create(['shop_id' => $shop->id, 'product_id' => $shop->product_id, 'asked_at' => now()->subDays(400)]);
    $recent = LargeDropCheck::factory()->create(['shop_id' => $shop->id, 'product_id' => $shop->product_id]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(LargeDropCheck::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(LargeDropCheck::query()->find($old->id))->toBeNull();
});

test('the price changes list names a dip the second reading did not show as a caught wrong price', function (): void {
    $shop = recordedDropShop();

    readPrices($shop, '40.00', '99.00');

    $rows = new PriceChangeLog($shop->product()->sole(), windowStart: null)->rows();

    expect(array_column($rows, 'to'))->toBe(['99.00', '40.00', '100.00'])
        ->and(array_column($rows, 'action'))->toBe([null, PriceChangeAction::WrongPriceCaught, null]);
});

test('a dip followed by a smaller dip that alerts does not claim no alert', function (): void {
    $shop = recordedDropShop();

    readPrices($shop, '40.00', '50.00');

    $rows = new PriceChangeLog($shop->product()->sole(), windowStart: null)->rows();

    expect(PriceDropEvent::count())->toBe(1)
        ->and(array_column($rows, 'to'))->toBe(['50.00', '40.00', '100.00'])
        ->and(array_column($rows, 'action'))->toBe([PriceChangeAction::Alert, null, null]);
});
