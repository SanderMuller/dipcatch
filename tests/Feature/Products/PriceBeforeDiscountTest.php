<?php declare(strict_types=1);

use App\Enums\ConsumerPriceIssue;
use App\Enums\ProductCategory;
use App\Enums\ScrapeStatus;
use App\Enums\ShopKind;
use App\Livewire\Products\ProductList;
use App\Livewire\Products\ProductShow;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Support\DiscountCheck;
use App\Support\PriceBeforeDiscount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00'));
});

function discountShop(?ProductCategory $category = null, string $claim = '100.00'): Shop
{
    $product = Product::factory()->create(['currency' => 'EUR', 'category' => $category]);

    return Shop::factory()->for($product)->create(['currency' => 'EUR', 'claimed_regular_price' => $claim]);
}

/**
 * One reading per day, from `$from` days ago to `$to` days ago (inclusive),
 * at the current clock time.
 *
 * @param  array<string, mixed>  $extra
 */
function readDaily(Shop $shop, int $from, int $to, string $price, ?string $claim = null, array $extra = []): void
{
    for ($day = $from; $day >= $to; $day--) {
        readAt($shop, CarbonImmutable::now()->subDays($day), $price, $claim, $extra);
    }
}

/**
 * @param  array<string, mixed>  $extra
 */
function readAt(Shop $shop, CarbonImmutable $at, string $price, ?string $claim = null, array $extra = []): void
{
    PriceCheck::query()->create([
        'shop_id' => $shop->id,
        'price' => $price,
        'currency' => 'EUR',
        'status' => ScrapeStatus::Ok,
        'checked_at' => $at,
        'claimed_regular_price' => $claim,
        'claim_read' => true,
        'shelf_inherited' => false,
        ...$extra,
    ]);
}

function discountFor(Shop $shop): ?DiscountCheck
{
    $product = $shop->product()->sole();

    return PriceBeforeDiscount::forShops($product, $product->shops()->get())[$shop->id] ?? null;
}

test('a promotion claiming the price before a permanent cut is measured against the cut', function (): void {
    $shop = discountShop();
    readDaily($shop, 120, 61, '100.00');
    readDaily($shop, 60, 8, '80.00');
    readDaily($shop, 7, 0, '70.00', claim: '100.00');

    $check = discountFor($shop);

    expect($check?->claimedRegularPrice)->toBe('100.00')
        // The 70s of the discount itself sit outside the half-open window.
        ->and($check?->lowestBefore)->toBe('80.00');
});

test('a progressive discount keeps one claim and shows no line', function (): void {
    $shop = discountShop();
    readDaily($shop, 60, 21, '100.00');
    readDaily($shop, 20, 11, '90.00', claim: '100.00');
    readDaily($shop, 10, 0, '80.00', claim: '100.00');

    expect(discountFor($shop))->toBeNull();
});

test('a rise inside one claim ends the run', function (): void {
    $shop = discountShop();
    readDaily($shop, 60, 31, '100.00');
    readDaily($shop, 30, 21, '60.00', claim: '100.00');
    readDaily($shop, 20, 11, '90.00', claim: '100.00');
    readDaily($shop, 10, 0, '80.00', claim: '100.00');

    expect(discountFor($shop)?->lowestBefore)->toBe('60.00');
});

test('a changed claim starts a new run', function (): void {
    $shop = discountShop(claim: '120.00');
    readDaily($shop, 60, 41, '100.00');
    readDaily($shop, 40, 11, '80.00', claim: '100.00');
    readDaily($shop, 10, 0, '80.00', claim: '120.00');

    $check = discountFor($shop);

    expect($check?->claimedRegularPrice)->toBe('120.00')
        ->and($check?->lowestBefore)->toBe('80.00');
});

test('a claim older than three months is measured against the last 30 days', function (): void {
    $shop = discountShop();
    readDaily($shop, 130, 101, '100.00');
    readDaily($shop, 100, 0, '80.00', claim: '100.00');

    expect(discountFor($shop)?->lowestBefore)->toBe('80.00');
});

test('a run of exactly three months keeps its own window, a day more does not', function (int $runDays, ?string $expected): void {
    $shop = discountShop();
    $runStart = CarbonImmutable::now()->subMonthsNoOverflow(3)->addDays(92 - $runDays);
    $at = $runStart->subDays(40);

    while ($at->lessThan($runStart)) {
        readAt($shop, $at, '100.00');
        $at = $at->addDay();
    }

    while ($at->lessThanOrEqualTo(CarbonImmutable::now())) {
        readAt($shop, $at, '80.00', '100.00');
        $at = $at->addDay();
    }

    expect(discountFor($shop)?->lowestBefore)->toBe($expected);
})->with([
    'exactly three months' => [92, null],
    'a day more' => [93, '80.00'],
]);

test('month-end arithmetic caps a run at the same calendar day three months back', function (string $runStart, ?string $expected): void {
    $this->travelTo(CarbonImmutable::parse('2026-05-31 12:00:00'));
    $shop = discountShop();
    $start = CarbonImmutable::parse($runStart . ' 12:00:00');
    $at = $start->subDays(40);

    while ($at->lessThanOrEqualTo(CarbonImmutable::now())) {
        $at->lessThan($start) ? readAt($shop, $at, '100.00') : readAt($shop, $at, '80.00', '100.00');
        $at = $at->addDay();
    }

    expect(discountFor($shop)?->lowestBefore)->toBe($expected);
})->with([
    // 31 May minus three months is 28 February, not 3 March.
    'inside the cap' => ['2026-03-01', null],
    'past the cap' => ['2026-02-27', '80.00'],
]);

test('a reading exactly at the window start counts', function (): void {
    $shop = discountShop();
    $discountStart = CarbonImmutable::now()->subDays(7);
    readDaily($shop, 40, 8, '100.00');
    readAt($shop, $discountStart->subDays(30), '90.00');
    readDaily($shop, 7, 0, '70.00', claim: '100.00');

    expect(discountFor($shop)?->lowestBefore)->toBe('90.00');
});

test('a gap over three days shows no line, a gap of exactly three days does', function (int $gapDays, bool $shows): void {
    $shop = discountShop();
    readDaily($shop, 60, 20 + $gapDays, '80.00');
    readDaily($shop, 20, 8, '80.00');
    readDaily($shop, 7, 0, '70.00', claim: '100.00');

    expect(discountFor($shop) !== null)->toBe($shows);
})->with([
    'four days' => [4, false],
    'exactly three days' => [3, true],
]);

test('a predecessor four days before the window, or a stale last reading, shows no line', function (string $case): void {
    $shop = discountShop();
    $discountStart = 7;

    if ($case === 'predecessor') {
        readAt($shop, CarbonImmutable::now()->subDays($discountStart + 30 + 4), '80.00');
        readDaily($shop, $discountStart + 29, $discountStart + 1, '80.00');
        readDaily($shop, $discountStart, 0, '70.00', claim: '100.00');
    } else {
        readDaily($shop, 60, 12, '80.00');
        readDaily($shop, 11, 4, '70.00', claim: '100.00');
    }

    expect(discountFor($shop))->toBeNull();
})->with(['predecessor', 'stale last reading']);

test('a gap inside a run longer than three months shows no line', function (): void {
    $shop = discountShop();
    readDaily($shop, 130, 101, '100.00');
    readDaily($shop, 100, 45, '80.00', claim: '100.00');
    readDaily($shop, 39, 0, '80.00', claim: '100.00');

    expect(discountFor($shop))->toBeNull();
});

test('today\'s reading must be evidence too', function (array $noEvidence): void {
    $shop = discountShop();
    readDaily($shop, 60, 8, '80.00');
    readDaily($shop, 7, 1, '70.00', claim: '100.00');
    readAt($shop, CarbonImmutable::now()->subHour(), '70.00', '100.00', $noEvidence);

    expect(discountFor($shop))->toBeNull();
})->with([
    'ex-VAT' => [['consumer_price_issue' => ConsumerPriceIssue::cases()[0]]],
    'carried-over bundle' => [['shelf_inherited' => true]],
]);

test('a shop first read less than 30 days before the discount shows no line', function (): void {
    $shop = discountShop();
    readDaily($shop, 20, 8, '80.00');
    readDaily($shop, 7, 0, '70.00', claim: '100.00');

    expect(discountFor($shop))->toBeNull();
});

test('only today\'s seller counts', function (): void {
    $shop = discountShop();

    for ($day = 60; $day >= 0; $day--) {
        $at = CarbonImmutable::now()->subDays($day);
        readAt($shop, $at->subHour(), '70.00', null, ['seller' => 'Seller A']);
        readAt($shop, $at, $day <= 7 ? '80.00' : '100.00', $day <= 7 ? '100.00' : null, ['seller' => 'Seller B']);
    }

    expect(discountFor($shop))->toBeNull();
});

test('a reading under a multi-buy bundle counts at its single-item price', function (): void {
    $shop = discountShop();
    readDaily($shop, 60, 8, '1.50', extra: ['single_item_price' => '2.00']);
    readDaily($shop, 7, 0, '1.80', claim: '2.20');
    $shop->forceFill(['claimed_regular_price' => '2.20'])->save();

    expect(discountFor($shop)?->lowestBefore)->toBe('2.00');
});

test('readings that are no evidence count as gaps', function (array $noEvidence): void {
    $shop = discountShop();
    readDaily($shop, 60, 21, '80.00');
    readDaily($shop, 20, 15, '80.00', extra: $noEvidence);
    readDaily($shop, 14, 8, '80.00');
    readDaily($shop, 7, 0, '70.00', claim: '100.00');

    expect(discountFor($shop))->toBeNull();
})->with([
    'a reader that cannot state a claim (the AH dataset fallback)' => [['claim_read' => false]],
    'carried-over bundle prices' => [['shelf_inherited' => true]],
    'rows from before this shipped' => [['claim_read' => null, 'shelf_inherited' => null]],
]);

test('an ex-VAT reading never becomes the low', function (): void {
    $shop = discountShop();
    readDaily($shop, 60, 8, '95.00');
    readAt($shop, CarbonImmutable::now()->subDays(20)->addHour(), '60.00', null, ['consumer_price_issue' => ConsumerPriceIssue::cases()[0]]);
    readDaily($shop, 7, 0, '90.00', claim: '100.00');

    expect(discountFor($shop)?->lowestBefore)->toBe('95.00');
});

test('readings before a repoint never count', function (): void {
    $shop = discountShop();
    readDaily($shop, 60, 8, '80.00');
    readDaily($shop, 7, 0, '70.00', claim: '100.00');
    $shop->forceFill(['repointed_at' => CarbonImmutable::now()->subDays(10)])->save();

    expect(discountFor($shop))->toBeNull();
});

test('perishable categories are skipped, even a shelf-stable item in one; uncategorised products are checked', function (?ProductCategory $category, bool $shows): void {
    $shop = discountShop($category);
    readDaily($shop, 60, 8, '80.00');
    readDaily($shop, 7, 0, '70.00', claim: '100.00');

    expect(discountFor($shop) !== null)->toBe($shows);
})->with([
    'fresh produce' => [ProductCategory::FreshProduce, false],
    'dairy (long-life milk too)' => [ProductCategory::DairyEggs, false],
    'bakery' => [ProductCategory::Bakery, false],
    'meat, fish, vegetarian' => [ProductCategory::MeatFishVeg, false],
    'uncategorised' => [null, true],
    'pantry' => [ProductCategory::Pantry, true],
]);

test('a claim at or below our low shows no line, and a reference shop none', function (string $case): void {
    $shop = discountShop();
    readDaily($shop, 60, 8, '100.00');
    readDaily($shop, 7, 0, '70.00', claim: '100.00');

    if ($case === 'reference') {
        $shop->forceFill(['kind' => ShopKind::Reference])->save();
        readDaily($shop, 60, 8, '80.00');
    }

    expect(discountFor($shop))->toBeNull();
})->with(['claim equals the low', 'reference']);

test('one query answers for every shop', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);

    $count = function (int $shops) use ($product): int {
        $product->shops()->delete();

        foreach (range(1, $shops) as $i) {
            $shop = Shop::factory()->for($product)->create(['currency' => 'EUR', 'claimed_regular_price' => '100.00']);
            readDaily($shop, 40, 8, '80.00');
            readDaily($shop, 7, 0, '70.00', claim: '100.00');
        }

        $shops = $product->shops()->get();
        DB::flushQueryLog();
        DB::enableQueryLog();
        PriceBeforeDiscount::forShops($product, $shops);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(1))->toBe(1)->and($count(4))->toBe(1);
});

test('the product page shows an undated claim in the headline and the shop row; the list shows none', function (): void {
    $shop = discountShop();
    readDaily($shop, 60, 8, '80.00');
    readDaily($shop, 7, 0, '70.00', claim: '100.00');
    $shop->forceFill(['current_price' => '70.00', 'single_item_price' => '70.00', 'last_success_at' => now()])->save();
    $product = $shop->product()->sole();
    $product->recomputeCheapestShop();
    $this->actingAs($product->user()->sole());

    $html = Livewire::test(ProductShow::class, ['product' => $product->refresh()])->html();

    expect(substr_count($html, 'data-test="discount-check"'))->toBe(2)
        ->and($html)->toContain('Shop says it was €100.00. Lowest here in the 30 days before: €80.00.');

    Livewire::test(ProductList::class)->assertDontSeeHtml('data-test="discount-check"');
});
