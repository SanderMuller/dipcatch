<?php declare(strict_types=1);

use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use App\Services\Drops\ReferenceValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * `Reference::compute()` reads a 30-day window: a segment query plus a count
 * over `price_checks`. `recomputeCheapestShop()` runs it once, before it takes
 * the row lock, and hands the value to whichever branch fires. This pins that
 * the drop branch reuses it instead of computing it again inside the lock.
 */
beforeEach(function (): void {
    Notification::fake();
});

test('the reference is computed once per recompute, and before the lock', function (): void {
    // A product sitting at 100.00 with one open history segment, so a
    // reference exists before the recompute runs.
    $user = User::factory()->create(['notify_via_filament' => true]);

    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'drop_threshold_pct' => '10.00',
        'drop_threshold_abs' => '5.00',
    ]);

    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/' . fake()->unique()->slug(),
        'currency' => 'EUR',
        'current_price' => '100.00',
    ]);

    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '100.00'])->save();

    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '100.00',
        'started_at' => now()->subHour(),
        'ended_at' => null,
    ]);

    // The check that carries the new price. It anchors the event row, so
    // without it the drop branch runs and writes nothing.
    $checkId = (int) PriceCheck::factory()->for($shop)->create(['price' => '85.00'])->id;
    $shop->update(['current_price' => '85.00']);
    $product->refresh();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $product->recomputeCheapestShop($checkId);

    $queries = array_map(
        static fn (array $entry): string => (string) $entry['query'],
        DB::getQueryLog(),
    );

    DB::disableQueryLog();

    // The window read the drop branch used to repeat inside the lock. One
    // count over `price_checks` means one `Reference::compute()`.
    $windowReads = array_keys(array_filter(
        $queries,
        static fn (string $query): bool => str_contains($query, 'from "price_checks"')
            && str_contains($query, 'count(*)'),
    ));

    $lockIndex = array_find_key(
        $queries,
        static fn (string $query): bool => str_contains($query, 'for update'),
    );

    expect($windowReads)->toHaveCount(1)
        ->and($lockIndex)->toBeInt()
        ->and($windowReads[0])->toBeLessThan((int) $lockIndex);

    // The refactor must not change which drops fire, nor what they fire
    // against. One check in the window is below MEDIAN_MIN_SAMPLES, so the
    // reference is the initial price of 100.00.
    $event = PriceDropEvent::query()->where('product_id', $product->id)->sole();

    expect((string) $event->reference_price)->toBe('100.00')
        ->and($event->reference_kind)->toBe(ReferenceValue::KIND_INITIAL)
        ->and((string) $event->new_price)->toBe('85.00');
});
