<?php declare(strict_types=1);

use App\Enums\ConsumerPriceIssue;
use App\Enums\PriceVerdict;
use App\Enums\ScrapeStatus;
use App\Enums\ShopKind;
use App\Livewire\Products\ProductShow;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\PriceVerdicts;
use App\Support\ShopPriceVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    // Late enough that the whole window is after the pack-size cutoff.
    $this->travelTo(CarbonImmutable::parse('2027-02-01 12:00:00', 'UTC'));
});

/**
 * One reading a day, oldest first, the last one an hour ago. The shop's
 * current price is the newest reading's price.
 *
 * @param  list<string>  $prices
 * @param  array<string, mixed>  $attributes
 */
function shopWithDailyPrices(array $prices, array $attributes = [], array $shopAttributes = []): Shop
{
    $shop = Shop::factory()->create(['current_price' => end($prices)] + $shopAttributes);
    readingsFor($shop, $prices, $attributes);

    return $shop;
}

/**
 * @param  list<string>  $prices
 * @param  array<string, mixed>  $attributes
 */
function readingsFor(Shop $shop, array $prices, array $attributes = [], int $endDaysAgo = 0): void
{
    $last = count($prices) - 1;

    insertPriceChecks($shop, array_map(fn (int $index): array => [
        'price' => $prices[$index],
        'checked_at' => now()->subDays($endDaysAgo + $last - $index)->subHour(),
    ] + $attributes, array_keys($prices)));
}

/** @return list<string> */
function prices(string $price, int $days): array
{
    return array_fill(0, $days, $price);
}

function verdictFor(Shop $shop): ?ShopPriceVerdict
{
    return PriceVerdicts::forShops(new Collection([$shop->refresh()]))[$shop->id] ?? null;
}

test('a price at the 25th percentile and 5% under the median is a good price', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 60), ...prices('1.90', 30)]);

    $verdict = verdictFor($shop);

    expect($verdict?->verdict)->toBe(PriceVerdict::Good)
        ->and($verdict?->low)->toBe('1.9000')
        ->and($verdict?->median)->toBe('2.0000')
        ->and($verdict?->usualRange())->toBe('Usually €1.90 to €2.00 for this pack');
});

test('a price at the 25th percentile but less than 5% under the median is normal', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 60), ...prices('1.91', 30)]);

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Normal);
});

test('a price above the 75th percentile and 5% over the median is a high price', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 85), ...prices('2.10', 5)]);

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::High);
});

test('a price above the 75th percentile but less than 5% over the median is normal', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 85), ...prices('2.09', 5)]);

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Normal);
});

test('a price that never moved is normal, with no range and no lowest badge', function (): void {
    $verdict = verdictFor(shopWithDailyPrices(prices('2.00', 90)));

    expect($verdict?->verdict)->toBe(PriceVerdict::Normal)
        ->and($verdict?->usualRange())->toBeNull()
        ->and($verdict?->lowestIn90Days)->toBeFalse();
});

test('a price a few cents off the usual one is normal', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 80), ...prices('1.97', 10)]);

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Normal);
});

test('thirty days with a reading are enough, twenty-nine are not', function (): void {
    expect(verdictFor(shopWithDailyPrices(prices('2.00', 30))))->not->toBeNull()
        ->and(verdictFor(shopWithDailyPrices(prices('2.00', 29))))->toBeNull();
});

test('a gap of more than seven days in the readings gives no verdict', function (): void {
    $shop = Shop::factory()->create(['current_price' => '2.00']);
    readingsFor($shop, prices('2.00', 40), endDaysAgo: 48);
    readingsFor($shop, prices('2.00', 40));

    expect(verdictFor($shop))->toBeNull();
});

test('a newest reading older than three days gives no verdict', function (): void {
    $shop = Shop::factory()->create(['current_price' => '2.00']);
    readingsFor($shop, prices('2.00', 60), endDaysAgo: 3);

    expect(verdictFor($shop))->toBeNull();
});

test('a newest reading that is out of stock, failed, or from another seller gives no verdict', function (array $newest): void {
    $shop = shopWithDailyPrices(prices('2.00', 60));
    PriceCheck::factory()->for($shop)->create(['price' => '2.00', 'checked_at' => now()->subMinutes(5)] + $newest);

    expect(verdictFor($shop))->toBeNull();
})->with([
    'out of stock' => [['in_stock' => false]],
    'failed' => [['status' => ScrapeStatus::HttpError, 'price' => null]],
    'another seller' => [['seller' => 'Other Seller BV']],
    'not a consumer price' => [['consumer_price_issue' => ConsumerPriceIssue::cases()[0]]],
]);

test('a price the newest reading does not show gives no verdict', function (): void {
    // A failed check expired a bundle and moved the stored price, with no
    // reading at the new price yet.
    $shop = shopWithDailyPrices(prices('2.00', 60));
    PriceCheck::factory()->for($shop)->failed()->create(['checked_at' => now()->subMinutes(5)]);
    $shop->forceFill(['current_price' => '2.50'])->save();

    expect(verdictFor($shop))->toBeNull();

    PriceCheck::factory()->for($shop)->create(['price' => '2.50', 'checked_at' => now()]);

    expect(verdictFor($shop))->not->toBeNull();
});

test('a promotion boundary passed since the newest reading gives no verdict until the next one', function (array $window): void {
    $shop = shopWithDailyPrices([...prices('2.00', 59), '1.50'], shopAttributes: $window);

    expect(verdictFor($shop))->toBeNull();

    PriceCheck::factory()->for($shop)->create(['price' => '1.50', 'checked_at' => now()]);

    expect(verdictFor($shop))->not->toBeNull();
})->with([
    // The window stays on the shop, as a source without the concept keeps it.
    'a window that ended' => fn (): array => ['promotion_starts_at' => now()->subDays(3), 'promotion_ends_at' => now()->subMinutes(30)],
    'a deal that started' => fn (): array => ['promotion_starts_at' => now()->subMinutes(30), 'promotion_ends_at' => now()->addDays(5)],
]);

test('the lowest price of 90 days is marked only with history that covers them', function (): void {
    $full = verdictFor(shopWithDailyPrices([...prices('2.00', 89), '1.80']));
    $short = verdictFor(shopWithDailyPrices([...prices('2.00', 39), '1.80']));

    expect($full?->lowestIn90Days)->toBeTrue()
        ->and($short)->not->toBeNull()
        ->and($short?->lowestIn90Days)->toBeFalse();
});

test('the lowest price of 90 days is marked only when no day was cheaper', function (string $today, bool $lowest): void {
    $verdict = verdictFor(shopWithDailyPrices([...prices('2.00', 55), ...prices('1.70', 5), ...prices('2.00', 29), $today]));

    expect($verdict?->lowestIn90Days)->toBe($lowest);
})->with([
    'an earlier dip went lower' => ['1.80', false],
    'level with the earlier dip' => ['1.70', true],
]);

test('only readings at today\'s pack size count', function (): void {
    $shop = Shop::factory()->create(['current_price' => '2.00', 'pack_quantity' => '400.00', 'pack_unit' => 'g']);
    readingsFor($shop, prices('2.00', 60), ['pack_quantity' => '500.00', 'pack_unit' => 'g'], endDaysAgo: 20);
    readingsFor($shop, prices('2.00', 20), ['pack_quantity' => '400.00', 'pack_unit' => 'g']);

    expect(verdictFor($shop))->toBeNull();
});

test('an unknown size followed by a new size leaves the unknown readings out', function (): void {
    $shop = Shop::factory()->create(['current_price' => '2.00', 'pack_quantity' => '400.00', 'pack_unit' => 'g']);
    readingsFor($shop, prices('2.00', 60), endDaysAgo: 20);
    readingsFor($shop, prices('2.00', 20), ['pack_quantity' => '400.00', 'pack_unit' => 'g']);

    expect(verdictFor($shop))->toBeNull();
});

test('legacy readings without a size count until the size changes after the cutoff', function (bool $changed): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'UTC'));
    $shop = Shop::factory()->create(['current_price' => '2.00', 'pack_quantity' => '400.00', 'pack_unit' => 'g']);
    // Before the 2026-10-14 cutoff: legacy rows with no size.
    readingsFor($shop, prices('3.00', 40), endDaysAgo: 49);
    // The first readings after the cutoff hold the size it had then.
    readingsFor($shop, prices('3.00', 8), ['pack_quantity' => $changed ? '500.00' : '400.00', 'pack_unit' => 'g'], endDaysAgo: 40);
    readingsFor($shop, prices('2.00', 40), ['pack_quantity' => '400.00', 'pack_unit' => 'g']);

    $verdict = verdictFor($shop);

    expect($verdict)->not->toBeNull()
        // Counted, the dearer older readings make today's price good.
        ->and($verdict?->verdict)->toBe($changed ? PriceVerdict::Normal : PriceVerdict::Good);
})->with(['size unchanged' => false, 'size changed' => true]);

test('legacy readings count while no reading after the cutoff exists yet', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));
    $shop = Shop::factory()->create(['current_price' => '2.00', 'pack_quantity' => '400.00', 'pack_unit' => 'g']);
    readingsFor($shop, prices('2.00', 60));

    expect(verdictFor($shop))->not->toBeNull();
});

test('the size baseline is the first reading after the cutoff, even one that is not evidence', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'UTC'));
    $shop = Shop::factory()->create(['current_price' => '2.00', 'pack_quantity' => '400.00', 'pack_unit' => 'g']);
    readingsFor($shop, prices('3.00', 40), endDaysAgo: 49);
    // Out of stock in the old 500 g pack, half an hour after the cutoff
    // (2026-10-14 00:00 in Amsterdam): the size changed after the cutoff.
    insertPriceChecks($shop, [['price' => '3.00', 'checked_at' => CarbonImmutable::parse('2026-10-13 22:30:00', 'UTC'), 'in_stock' => false, 'pack_quantity' => '500.00', 'pack_unit' => 'g']]);
    readingsFor($shop, prices('3.00', 8), ['pack_quantity' => '400.00', 'pack_unit' => 'g'], endDaysAgo: 40);
    readingsFor($shop, prices('2.00', 40), ['pack_quantity' => '400.00', 'pack_unit' => 'g']);

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Normal);
});

test('only readings since a repoint count', function (): void {
    $shop = Shop::factory()->create(['current_price' => '2.00', 'repointed_at' => now()->subDays(40)]);
    // Counted, the dearer readings of the old URL would make €2.00 good.
    readingsFor($shop, prices('3.00', 50), endDaysAgo: 41);
    readingsFor($shop, prices('2.00', 40));

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Normal);
});

test('only readings from today\'s seller count', function (): void {
    $shop = Shop::factory()->create(['current_price' => '2.00']);
    readingsFor($shop, prices('3.00', 50), ['seller' => 'Old Seller BV'], endDaysAgo: 40);
    readingsFor($shop, prices('2.00', 40), ['seller' => 'New Seller BV']);

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Normal);
});

test('a reference shop gets no verdict', function (): void {
    $shop = shopWithDailyPrices(prices('2.00', 60), shopAttributes: ['kind' => ShopKind::Reference]);

    expect(verdictFor($shop))->toBeNull();
});

test('a reading that is not evidence is left out of the history', function (string $column, mixed $value): void {
    $shop = Shop::factory()->create(['current_price' => '2.00']);
    // Counted, the dearer readings would make €2.00 good.
    readingsFor($shop, prices('3.00', 50), [$column => $value], endDaysAgo: 40);
    readingsFor($shop, prices('2.00', 40));

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Normal);
})->with([
    'out of stock' => ['in_stock', false],
    'inherited' => ['shelf_inherited', true],
    'not a consumer price' => ['consumer_price_issue', ConsumerPriceIssue::cases()[0]->value],
    'failed' => ['status', ScrapeStatus::HttpError->value],
]);

test('a price under the median by the margin but above the 25th percentile is normal', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 60), ...prices('1.00', 29), '1.50']);

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Normal);
});

test('a price over the median by the margin but not above the 75th percentile is normal', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 60), ...prices('3.00', 29), '2.50']);

    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Normal);
});

test('a percentile between two different days is interpolated', function (): void {
    // 31 days: the 25th percentile sits halfway between the 8th and 9th.
    $verdict = verdictFor(shopWithDailyPrices([...prices('1.00', 8), ...prices('2.00', 23)]));

    expect($verdict?->low)->toBe('1.5000');
});

test('several readings on one day count as one day, at its lowest price', function (): void {
    $shop = Shop::factory()->create(['current_price' => '1.60']);
    $rows = [];

    foreach (range(39, 0) as $daysAgo) {
        $rows[] = ['price' => '2.00', 'checked_at' => now()->subDays($daysAgo)->subHours(2)];
        $rows[] = ['price' => '1.60', 'checked_at' => now()->subDays($daysAgo)->subHour()];
    }

    insertPriceChecks($shop, $rows);
    $verdict = verdictFor($shop);

    expect($verdict?->verdict)->toBe(PriceVerdict::Normal)
        ->and($verdict?->low)->toBe('1.6000');

    // 29 days of four readings each are still too few days.
    $short = Shop::factory()->create(['current_price' => '1.60']);
    insertPriceChecks($short, array_merge(...array_map(fn (int $daysAgo): array => array_map(
        fn (int $hour): array => ['price' => '1.60', 'checked_at' => now()->subDays($daysAgo)->subHours($hour)],
        [1, 4, 7, 10],
    ), range(28, 0))));

    expect(verdictFor($short))->toBeNull();
});

test('a shop whose reader cannot read a "was" price still gets a verdict', function (): void {
    $shop = shopWithDailyPrices(prices('2.00', 60), ['claim_read' => false, 'shelf_inherited' => null]);

    expect(verdictFor($shop))->not->toBeNull();
});

test('the verdicts of every shop come from two queries', function (): void {
    $queriesFor = function (int $count): int {
        $product = Product::factory()->create();
        $shops = new Collection();

        foreach (range(1, $count) as $index) {
            $shop = Shop::factory()->for($product)->create(['url' => "https://shop.test/p/{$index}", 'current_price' => '2.00']);
            readingsFor($shop, prices('2.00', 40));
            $shops->push($shop->refresh());
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $verdicts = PriceVerdicts::forShops($shops);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($verdicts)->toHaveCount($count);

        return $queries;
    };

    expect($queriesFor(3))->toBe(2)
        ->and($queriesFor(1))->toBe(2);
});

test('a second read of the same shops comes from the cache', function (): void {
    $shops = new Collection([shopWithDailyPrices([...prices('2.00', 59), '1.80']), shopWithDailyPrices(prices('2.00', 10))]);
    $first = PriceVerdicts::forShops($shops);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $second = PriceVerdicts::forShops($shops);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($second)->toEqual($first)->toHaveCount(1);
});

test('a new check clears the cached history', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 59), '1.80']);
    expect(verdictFor($shop)?->verdict)->toBe(PriceVerdict::Good);

    // Another seller from now on: the history of the old one no longer counts.
    PriceCheck::factory()->for($shop)->create(['price' => '1.80', 'checked_at' => now(), 'seller' => 'New Seller BV']);

    expect(verdictFor($shop))->toBeNull();
});

test('a new pack size on the shop clears the cached history', function (): void {
    $size = ['pack_quantity' => '400.00', 'pack_unit' => 'g'];
    $shop = shopWithDailyPrices([...prices('2.00', 59), '1.80'], $size, $size);
    expect(verdictFor($shop))->not->toBeNull();

    $shop->forceFill(['pack_quantity' => '500.00'])->save();

    expect(verdictFor($shop))->toBeNull();
});

test('a promotion boundary that passes inside the cache hour removes the verdict at once', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 59), '1.80'], shopAttributes: ['promotion_starts_at' => now()->subDays(2), 'promotion_ends_at' => now()->addMinutes(10)]);
    expect(verdictFor($shop))->not->toBeNull();

    $this->travel(20)->minutes();

    expect(verdictFor($shop))->toBeNull();
});

test('the alert flag reads past the cache and writes nothing to it', function (): void {
    $shop = shopWithDailyPrices(prices('2.00', 90));
    expect(PriceVerdicts::forShops(new Collection([$shop]))[$shop->id]->lowestIn90Days)->toBeFalse();

    DB::transaction(function () use ($shop): void {
        // The way a check writes: the cache is cleared only after the commit.
        PriceCheck::factory()->for($shop)->create(['price' => '1.50', 'checked_at' => now()->subMinutes(5)]);
        $shop->forceFill(['current_price' => '1.50'])->save();

        expect(PriceVerdicts::isLowestIn90Days($shop))->toBeTrue()
            // Still the entry from before the check: the alert read stored nothing.
            ->and(PriceVerdicts::forShops(new Collection([$shop]))[$shop->id] ?? null)->toBeNull();
    });

    expect(PriceVerdicts::forShops(new Collection([$shop]))[$shop->id]->lowestIn90Days)->toBeTrue();
});

test('a rolled-back check leaves the cached history in place', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 59), '1.80']);
    $before = verdictFor($shop);

    try {
        DB::transaction(function () use ($shop): void {
            PriceCheck::factory()->for($shop)->create(['price' => '1.80', 'checked_at' => now(), 'seller' => 'New Seller BV']);

            throw new RuntimeException('Rolled back.');
        });
    } catch (RuntimeException) {
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $after = verdictFor($shop);
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'price_checks'))->count();
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($after)->toEqual($before);
});

test('a new check clears the cached history on the database cache store too', function (): void {
    config(['cache.default' => 'database']);
    $shop = shopWithDailyPrices([...prices('2.00', 59), '1.80']);
    expect(verdictFor($shop))->not->toBeNull();

    PriceCheck::factory()->for($shop)->create(['price' => '1.80', 'checked_at' => now(), 'seller' => 'New Seller BV']);

    expect(verdictFor($shop))->toBeNull();
});

test('a reader holding the shop from before a size change cannot cache the old size\'s history', function (): void {
    $size = ['pack_quantity' => '400.00', 'pack_unit' => 'g'];
    $shop = shopWithDailyPrices([...prices('2.00', 59), '1.80'], $size, $size);
    $stale = Shop::query()->findOrFail($shop->id);

    $shop->forceFill(['pack_quantity' => '500.00'])->save();

    expect(PriceVerdicts::forShops(new Collection([$stale])))->toHaveCount(1)
        ->and(verdictFor($shop))->toBeNull();
});

test('a reader that read the history before a check cannot cache it after the check', function (): void {
    $shop = shopWithDailyPrices([...prices('2.00', 59), '1.80']);
    $checked = false;

    // A check commits while the reader runs its queries; the reader stores
    // what it read after that.
    DB::listen(function (QueryExecuted $query) use ($shop, &$checked): void {
        if (! $checked && str_contains($query->sql, 'left join lateral')) {
            $checked = true;
            PriceCheck::factory()->for($shop)->create(['price' => '1.80', 'checked_at' => now(), 'seller' => 'New Seller BV']);
        }
    });

    expect(verdictFor($shop))->not->toBeNull()
        ->and($checked)->toBeTrue()
        ->and(verdictFor($shop))->toBeNull();
});

test('the product page shows the verdict, the range and the lowest badge, and nothing for a new shop', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $known = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '1.80']);
    readingsFor($known, [...prices('2.00', 60), ...prices('1.90', 29), '1.80']);
    $new = Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '1.90']);
    readingsFor($new, prices('1.90', 10));

    $this->actingAs($user);

    $html = livewire(ProductShow::class, ['product' => $product])
        ->assertSee('Good price')
        ->assertSee('Lowest in 90 days')
        ->assertSee('Usually €1.90 to €2.00 for this pack')
        ->html();

    expect(substr_count($html, 'data-test="price-verdict"'))->toBe(1);
});
