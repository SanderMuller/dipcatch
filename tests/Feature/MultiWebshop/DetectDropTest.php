<?php declare(strict_types=1);

use App\Actions\Drops\DetectDrop;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\Reference;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

test('writes price_drop_event with explicit price_check_id and triggered_by_shop_id', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create(['current_price' => '70.00']);
    $product->forceFill([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '70.00',
        'drop_threshold_pct' => '10.00',
        'drop_threshold_abs' => '5.00',
    ])->save();

    // Seed a long-running 100 segment so reference is well-defined.
    foreach (range(1, 8) as $i) {
        ProductCheapestHistory::factory()->for($product)->create([
            'cheapest_shop_id' => $shop->id,
            'cheapest_price' => '100.00',
            'started_at' => now()->subDays(20 + $i),
            'ended_at' => now()->subDays(19 + $i),
        ]);
    }

    $check = PriceCheck::factory()->for($shop)->create(['price' => '70.00']);

    app(DetectDrop::class)($product, $check->id);

    $event = PriceDropEvent::query()->where('product_id', $product->id)->first();

    expect($event)->not->toBeNull()
        ->and($event->price_check_id)->toBe((int) $check->id)
        ->and($event->triggered_by_shop_id)->toBe($shop->id)
        ->and((string) $event->new_price)->toBe('70.00');

    Notification::assertSentTo($product->user, PriceDropNotification::class);
});

test('clearLatchIfRecovered clears latch when new price meets reference', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create(['current_price' => '100.00']);
    $product->forceFill([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '100.00',
        'last_notified_price' => '70.00',
        'last_notified_at' => now()->subHour(),
    ])->save();

    $reference = app(Reference::class)->compute($product);

    app(DetectDrop::class)->clearLatchIfRecovered($product, '100.00', $reference);

    $product->refresh();

    expect($product->last_notified_price)->toBeNull()
        ->and($product->last_notified_at)->toBeNull();
});

test('clearLatchIfRecovered with null newPrice clears latch (no eligible offer)', function (): void {
    $product = Product::factory()->create([
        'last_notified_price' => '50.00',
        'last_notified_at' => now()->subHour(),
    ]);

    app(DetectDrop::class)->clearLatchIfRecovered($product, newPrice: null, reference: null);

    $product->refresh();

    expect($product->last_notified_price)->toBeNull();
});

test('clearLatchIfRecovered leaves latch alone when not recovered', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create(['current_price' => '60.00']);
    $product->forceFill([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '60.00',
        'last_notified_price' => '50.00',
        'last_notified_at' => now()->subHour(),
    ])->save();

    // Plant lots of 100-priced segments → reference ≈ 100; cheapest 60 is NOT recovered.
    foreach (range(1, 10) as $i) {
        ProductCheapestHistory::factory()->for($product)->create([
            'cheapest_shop_id' => $shop->id,
            'cheapest_price' => '100.00',
            'started_at' => now()->subDays(20 + $i),
            'ended_at' => now()->subDays(19 + $i),
        ]);
    }

    $reference = app(Reference::class)->compute($product);

    app(DetectDrop::class)->clearLatchIfRecovered($product, '60.00', $reference);

    $product->refresh();

    expect((string) $product->last_notified_price)->toBe('50.00')
        ->and($product->last_notified_at)->not->toBeNull();
});

test('skips when cheapest_price is null', function (): void {
    $product = Product::factory()->create(['cheapest_price' => null]);

    app(DetectDrop::class)($product, null);

    expect(PriceDropEvent::query()->count())->toBe(0);
    Notification::assertNothingSent();
});
