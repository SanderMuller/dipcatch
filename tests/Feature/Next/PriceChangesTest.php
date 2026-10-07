<?php declare(strict_types=1);

use App\Charts\PriceChangeAction;
use App\Charts\PriceChangeLog;
use App\Livewire\Products\PriceChanges;
use App\Models\LargeDropCheck;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

/** A Pro owner's product with one shop, its price history written by `segment()`. */
function priceLogProduct(bool $pro = true): Shop
{
    $user = User::factory()->create();

    if ($pro) {
        subscribeUser($user, 'active');
    }

    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    return Shop::factory()->for($product)->create(['url' => 'https://drogist.test/p/1']);
}

/** One segment, started `$daysAgo` days ago and ended at the next one; returns its triggering check id. */
function segment(Shop $shop, ?string $price, float $daysAgo, ?float $endedDaysAgo = null, array $attributes = []): int
{
    $check = PriceCheck::factory()->for($shop)->create(['price' => $price]);

    ProductCheapestHistory::factory()->for($shop->product()->sole())->create([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => $price,
        'started_at' => now()->subMinutes((int) ($daysAgo * 1440)),
        'ended_at' => $endedDaysAgo === null ? null : now()->subMinutes((int) ($endedDaysAgo * 1440)),
        'triggering_price_check_id' => $check->id,
    ] + $attributes);

    return (int) $check->id;
}

/** @return list<array<string, mixed>> */
function logRows(Shop $shop, ?CarbonImmutable $windowStart = null): array
{
    return new PriceChangeLog($shop->product()->sole(), $windowStart)->rows();
}

test('lists each change of the lowest price, newest first, with the shop and the change', function (): void {
    $shop = priceLogProduct();
    segment($shop, '6.49', 10, 3);
    segment($shop, '6.99', 3);

    $rows = logRows($shop);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['shop' => 'drogist.test', 'from' => '6.49', 'to' => '6.99', 'changePct' => 8, 'action' => null])
        ->and($rows[1])->toMatchArray(['from' => null, 'to' => '6.49', 'changePct' => null]);
});

test('says DipCatch caught a wrong price on a rejected dip, and nothing on the return', function (): void {
    $shop = priceLogProduct();
    segment($shop, '6.49', 10, 2);
    $dip = segment($shop, '3.39', 2, 1.99);
    segment($shop, '6.49', 1.99);
    LargeDropCheck::factory()->rejected()->create(['shop_id' => $shop->id, 'product_id' => $shop->product_id, 'price_check_id' => $dip]);

    $rows = logRows($shop);

    expect($rows[0]['action'])->toBeNull()
        ->and($rows[1]['action'])->toBe(PriceChangeAction::WrongPriceCaught);
});

test('names the alert, the reached alert price and the re-check by what happened', function (): void {
    $shop = priceLogProduct();
    segment($shop, '9.00', 20, 15);
    $confirmed = segment($shop, '4.00', 15, 12);
    $small = segment($shop, '8.00', 12, 9);
    segment($shop, '7.00', 9, 6);
    $waiting = segment($shop, '3.00', 6, 3);
    $stale = segment($shop, '2.00', 0.5);

    LargeDropCheck::factory()->confirmed()->create(['shop_id' => $shop->id, 'product_id' => $shop->product_id, 'price_check_id' => $confirmed]);
    PriceDropEvent::factory()->create(['product_id' => $shop->product_id, 'price_check_id' => $small]);
    TargetPriceEvent::factory()->create(['product_id' => $shop->product_id, 'shop_id' => $shop->id, 'fired_at' => now()->subDays(8)]);
    LargeDropCheck::factory()->create(['shop_id' => $shop->id, 'product_id' => $shop->product_id, 'price_check_id' => $waiting, 'asked_at' => now()->subDays(6)]);
    LargeDropCheck::factory()->create(['shop_id' => $shop->id, 'product_id' => $shop->product_id, 'price_check_id' => $stale, 'asked_at' => now()->subHours(12)]);

    expect(array_column(logRows($shop), 'action'))->toBe([
        PriceChangeAction::Rechecking,
        PriceChangeAction::RecheckFailed,
        PriceChangeAction::ReachedAlertPrice,
        PriceChangeAction::Alert,
        PriceChangeAction::Alert,
        null,
    ]);
});

test('skips a segment that moved only the best value or the pack size', function (): void {
    $shop = priceLogProduct();
    segment($shop, '6.49', 10, 5);
    segment($shop, '6.49', 5, attributes: ['pack_quantity' => '250.00', 'pack_unit' => 'ml']);

    expect(logRows($shop))->toHaveCount(1);
});

test('names no shop for a change set before the shop was pointed at another page', function (): void {
    $shop = priceLogProduct();
    segment($shop, '6.49', 10);
    $shop->forceFill(['repointed_at' => now()->subDays(5)])->save();

    expect(logRows($shop)[0]['shop'])->toBeNull();
});

test('keeps a change before the window out, but uses it as the old price', function (): void {
    $shop = priceLogProduct();
    segment($shop, '9.00', 100, 20);
    segment($shop, '6.00', 20);

    $rows = logRows($shop, CarbonImmutable::now()->subDays(30));

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['from' => '9.00', 'to' => '6.00']);
});

test('reads the list in a fixed number of queries', function (): void {
    $shop = priceLogProduct();
    foreach (range(1, 12) as $day) {
        segment($shop, (string) (5 + $day) . '.00', 40 - $day * 2, 38 - $day * 2);
    }

    $log = new PriceChangeLog($shop->product()->sole(), windowStart: null);

    DB::enableQueryLog();
    $log->rows();

    expect(DB::getQueryLog())->toHaveCount(5);
});

test('a Pro owner opens the list and pages it ten at a time', function (): void {
    $shop = priceLogProduct();
    foreach (range(1, 12) as $day) {
        segment($shop, (string) (5 + $day) . '.00', 40 - $day * 2, 38 - $day * 2);
    }

    $this->actingAs($shop->product()->sole()->user()->sole());

    $component = livewire(PriceChanges::class, ['product' => $shop->product()->sole(), 'range' => '90'])
        ->assertDontSee('drogist.test')
        ->call('toggle')
        ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 10)
        ->assertViewHas('hasMore', true)
        ->call('showMore')
        ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 12)
        ->assertViewHas('hasMore', false);

    $component->assertSee('drogist.test');
});

test('a Free owner sees the Pro note and no rows', function (): void {
    $shop = priceLogProduct(pro: false);
    segment($shop, '6.49', 10);

    $this->actingAs($shop->product()->sole()->user()->sole());

    livewire(PriceChanges::class, ['product' => $shop->product()->sole(), 'range' => '90'])
        ->call('toggle')
        ->assertSeeHtml('data-test="price-changes-pro"')
        ->assertViewHas('rows', []);
});

test('another account cannot open the list', function (): void {
    $shop = priceLogProduct();

    $this->actingAs(User::factory()->create());

    livewire(PriceChanges::class, ['product' => $shop->product()->sole(), 'range' => '90'])->assertForbidden();
});

test('the product page shows the toggle and the public page does not', function (): void {
    $shop = priceLogProduct();
    $product = $shop->product()->sole();
    segment($shop, '6.49', 10);

    $this->actingAs($product->user()->sole())->get(route('app.products.show', $product))->assertOk()->assertSeeHtml('data-test="price-changes"');

    $product->forceFill(['share_slug' => str_repeat('a', 32)])->save();

    $this->get((string) $product->publicShareUrl())->assertOk()->assertDontSeeHtml('data-test="price-changes"');
});
