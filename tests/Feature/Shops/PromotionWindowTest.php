<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\DutchDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
});

test('a window needs an end date', function (): void {
    expect(PromotionWindow::make(endsAt: null, startsAt: CarbonImmutable::now()))->toBeNull();
});

test('a start after its own end is refused instead of guessed at', function (): void {
    $window = PromotionWindow::make(
        endsAt: CarbonImmutable::parse('2026-09-06'),
        startsAt: CarbonImmutable::parse('2026-09-08'),
    );

    expect($window)->toBeNull();
});

test('an end-only window is a promotion running now that ends then', function (): void {
    $window = PromotionWindow::make(endsAt: CarbonImmutable::now()->addDays(3));

    expect($window?->isRunning())->toBeTrue()
        ->and($window?->hasNotStarted())->toBeFalse()
        ->and($window?->startsAt)->toBeNull();
});

test('a window is told apart before its start and after its end', function (): void {
    $future = PromotionWindow::make(
        endsAt: CarbonImmutable::now()->addDays(9),
        startsAt: CarbonImmutable::now()->addDays(2),
    );
    $past = PromotionWindow::make(endsAt: CarbonImmutable::now()->subDay());

    expect($future?->isRunning())->toBeFalse()
        ->and($future?->hasNotStarted())->toBeTrue()
        ->and($future?->hasEnded())->toBeFalse()
        // Both are "not running": only the two questions tell them apart.
        ->and($past?->isRunning())->toBeFalse()
        ->and($past?->hasNotStarted())->toBeFalse()
        ->and($past?->hasEnded())->toBeTrue();
});

test('a promotion ending today is still running late in the Amsterdam evening', function (): void {
    // 2026-09-06 23:59:59 Europe/Amsterdam, the day boundary a date-only
    // source means, is 21:59:59 UTC.
    $window = PromotionWindow::make(endsAt: CarbonImmutable::parse('2026-09-06 21:59:59', 'UTC'));

    $elevenPmLocal = CarbonImmutable::parse('2026-09-06 23:00', 'Europe/Amsterdam');

    expect($window?->isRunning($elevenPmLocal))->toBeTrue()
        ->and($window?->isRunning($elevenPmLocal->addHours(2)))->toBeFalse();
});

test('an empty label is stored as none rather than an empty string', function (): void {
    expect(PromotionWindow::make(endsAt: CarbonImmutable::now()->addDay(), label: '  ')?->label)->toBeNull();
});

test('the snapshot copy helper carries every field through', function (): void {
    $window = PromotionWindow::make(endsAt: CarbonImmutable::now()->addDay(), label: 'VOOR 1.69');

    $original = new ShopSnapshot(
        title: 'Cheese',
        imageUrl: 'https://shop.test/x.png',
        price: '1.69',
        currency: 'EUR',
        inStock: true,
        raw: ['source' => 'x'],
        gtin: '8712243044506',
        gtinAuthoritative: true,
        promotionWindow: $window,
        promotionWindowAuthoritative: true,
    );

    $copy = $original->with(packSize: '150 g', packSizeAuthoritative: true);

    expect($copy->packSize)->toBe('150 g')
        ->and($copy->packSizeAuthoritative)->toBeTrue()
        ->and($copy->title)->toBe('Cheese')
        ->and($copy->gtin)->toBe('8712243044506')
        ->and($copy->gtinAuthoritative)->toBeTrue()
        ->and($copy->promotionWindow)->toBe($window)
        ->and($copy->promotionWindowAuthoritative)->toBeTrue();
});

test('the shop returns a window that has already ended', function (): void {
    $shop = Shop::factory()->for(Product::factory())->create([
        'promotion_ends_at' => now()->subDays(2),
        'promotion_label' => 'VOOR 1.69',
    ]);

    expect($shop->promotionWindow()?->hasEnded())->toBeTrue()
        ->and($shop->promotionWindow()?->label)->toBe('VOOR 1.69');
});

test('a shop with no end date has no window', function (): void {
    $shop = Shop::factory()->for(Product::factory())->create(['promotion_starts_at' => now()]);

    expect($shop->promotionWindow())->toBeNull();
});

test('a failed check leaves a stored window alone', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('boom', 500),
    ]);

    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create([
        'url' => 'https://shop.test/p/1',
        'promotion_ends_at' => now()->addDays(3),
        'promotion_label' => 'VOOR 1.69',
    ]);

    CheckShopPrice::dispatchSync($shop);

    expect($shop->refresh()->last_status)->not->toBe(ScrapeStatus::Ok)
        ->and($shop->promotion_label)->toBe('VOOR 1.69');
});

/**
 * A source under our control, so the persistence rules can be driven
 * without waiting for a real shop to run a promotion.
 */
function shopReporting(ShopSnapshot $snapshot): void
{
    app()->forgetInstance(AdapterResolver::class);
    app()->singleton('test.promotion.adapter', fn (): ShopAdapter => new class ($snapshot) implements ShopAdapter {
        public function __construct(private ShopSnapshot $snapshot) {}

        public function key(): string
        {
            return 'test-promotion';
        }

        public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
        {
            return ExtractionResult::success($this->snapshot);
        }
    });

    config()->set('dipcatch.adapters', ['test.promotion.adapter']);
}

function snapshotWith(?PromotionWindow $window, bool $authoritative): ShopSnapshot
{
    return new ShopSnapshot(
        title: 'Cheese',
        imageUrl: null,
        price: '1.69',
        currency: 'EUR',
        inStock: true,
        promotionWindow: $window,
        promotionWindowAuthoritative: $authoritative,
    );
}

function shopWithStoredWindow(): Shop
{
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/9' => Http::response('<html>x</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    return Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create([
        'url' => 'https://shop.test/p/9',
        'promotion_starts_at' => now()->subDay(),
        'promotion_ends_at' => now()->addDays(3),
        'promotion_label' => 'VOOR 1.69',
    ]);
}

test('a reported window is written to the shop', function (): void {
    $shop = shopWithStoredWindow();
    shopReporting(snapshotWith(
        PromotionWindow::make(
            endsAt: CarbonImmutable::parse('2026-09-20 21:59:59'),
            startsAt: CarbonImmutable::parse('2026-09-14 22:00:00'),
            label: '2 VOOR 3.00',
        ),
        authoritative: true,
    ));

    CheckShopPrice::dispatchSync($shop);

    expect($shop->refresh()->promotion_label)->toBe('2 VOOR 3.00')
        ->and($shop->promotion_ends_at?->toDateString())->toBe('2026-09-20')
        ->and($shop->promotion_starts_at?->toDateString())->toBe('2026-09-14');
});

test('an Amsterdam day boundary survives the round trip to the database', function (): void {
    $shop = shopWithStoredWindow();
    shopReporting(snapshotWith(
        // What every date-only source produces: the close of that day in
        // Dutch retail terms.
        PromotionWindow::make(endsAt: DutchDate::endOfDay('2026-09-06')),
        authoritative: true,
    ));

    CheckShopPrice::dispatchSync($shop);

    expect($shop->refresh()->promotionWindow()?->endsAt->setTimezone('Europe/Amsterdam')->toDateTimeString())
        ->toBe('2026-09-06 23:59:59');
});

test('an authoritative source reporting no window clears the stored one', function (): void {
    $shop = shopWithStoredWindow();
    shopReporting(snapshotWith(null, authoritative: true));

    CheckShopPrice::dispatchSync($shop);

    expect($shop->refresh()->promotion_ends_at)->toBeNull()
        ->and($shop->promotion_label)->toBeNull()
        ->and($shop->promotionWindow())->toBeNull();
});

test('a source with no promotion concept leaves the stored window alone', function (): void {
    $shop = shopWithStoredWindow();
    shopReporting(snapshotWith(null, authoritative: false));

    CheckShopPrice::dispatchSync($shop);

    expect($shop->refresh()->promotion_label)->toBe('VOOR 1.69')
        ->and($shop->promotion_ends_at)->not->toBeNull();
});
