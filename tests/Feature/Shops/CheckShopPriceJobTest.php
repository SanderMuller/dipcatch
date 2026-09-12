<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\RecheckJitter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

function fakeJsonLdResponse(string $host, string $path, string $price = '60.00', string $currency = 'EUR', string $name = 'X'): array
{
    $json = json_encode([
        '@type' => 'Product',
        'name' => $name,
        'offers' => [
            '@type' => 'Shop',
            'price' => $price,
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    return [
        "https://{$host}/robots.txt" => Http::response('', 404),
        "https://{$host}{$path}" => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ];
}

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
});

test('successful check writes price_check, updates offer, recomputes cheapest', function (): void {
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '60.00'));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '90.00',
        'consecutive_failures' => 2,
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();
    $product->refresh();

    expect((string) $shop->current_price)->toBe('60.00')
        ->and($shop->consecutive_failures)->toBe(0)
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and($shop->adapter_key)->toBe('jsonld')
        ->and((string) $product->cheapest_price)->toBe('60.00')
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1);
});

test('parse failure increments main counter and writes failed price_check', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('<html><body>no metadata</body></html>', 200),
    ]);

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 1,
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );

    $shop->refresh();
    expect($shop->consecutive_failures)->toBe(2)
        ->and($shop->consecutive_5xx_failures)->toBe(0)
        ->and($shop->health)->toBe(ShopHealth::Ok);
});

test('5xx increments the 5xx counter only', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('oops', 503),
    ]);

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 0,
        'consecutive_5xx_failures' => 0,
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );

    $shop->refresh();
    expect($shop->consecutive_failures)->toBe(0)
        ->and($shop->consecutive_5xx_failures)->toBe(1)
        ->and($shop->last_status)->toBe(ScrapeStatus::TransientServerError);
});

test('main counter reaching dead_after flips health to dead + active=false', function (): void {
    config()->set('dipcatch.shop.dead_after', 3);

    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('no metadata', 200),
    ]);

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 2,
        'active' => true,
        'health' => 'failing',
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );

    $shop->refresh();
    expect($shop->health)->toBe(ShopHealth::Dead)
        ->and($shop->active)->toBeFalse();
});

test('robots disallow flips offer to dead immediately', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200),
        'https://shop.test/p/1' => Http::response('<html></html>', 200),
    ]);

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'health' => 'ok',
        'active' => true,
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );

    $shop->refresh();
    expect($shop->health)->toBe(ShopHealth::Dead)
        ->and($shop->active)->toBeFalse()
        ->and($shop->last_status)->toBe(ScrapeStatus::RobotsDisallowed);
});

test('inactive or dead offer is skipped', function (): void {
    $shop = Shop::factory()->dead()->create(['url' => 'https://shop.test/p/1']);

    Http::fake();

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );

    Http::assertNothingSent();
    expect(PriceCheck::query()->count())->toBe(0);
});

test('per-host rate limit releases instead of writing a failed check or ticking counter', function (): void {
    $perMinute = config()->integer('dipcatch.fetcher.rate_limit_per_minute', 12);
    for ($i = 0; $i < $perMinute; $i++) {
        RateLimiter::hit(ShopFetcher::throttleKey('shop.test'));
    }
    // Pre-warm robots cache so the policy check doesn't issue an HTTP call —
    // otherwise assertNothingSent below would see the robots.txt fetch.
    Cache::put('dipcatch:robots:shop.test', [], 60);
    Http::fake();

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 0,
    ]);

    // handle() catches RateLimitedByHost and calls $this->release(). Without a
    // queued job context, InteractsWithQueue::release() is a no-op — what we
    // care about is that NO PriceCheck row is written and the failure counter
    // does NOT tick, even though the fetcher rejected the host.
    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );

    Http::assertNothingSent();
    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(0)
        ->and($shop->fresh()->consecutive_failures)->toBe(0);
});

test('successful check resets both counters and clears failing health', function (): void {
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1'));

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'consecutive_failures' => 5,
        'consecutive_5xx_failures' => 4,
        'health' => 'failing',
    ]);

    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );

    $shop->refresh();
    expect($shop->consecutive_failures)->toBe(0)
        ->and($shop->consecutive_5xx_failures)->toBe(0)
        ->and($shop->health)->toBe(ShopHealth::Ok);
});

test('a scraped check parses the pack size from the JSON-LD title', function (): void {
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '1.79', name: 'HiPRO Protein Drink Mango 300ml'));

    $shop = Shop::factory()->create(['url' => 'https://shop.test/p/1']);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();
    expect((string) $shop->pack_quantity)->toBe('300.00')
        ->and($shop->pack_unit)->toBe('ml')
        ->and($shop->unitPrice())->toBe('5.97')
        ->and($shop->unitPriceLabel())->toBe('/l');
});

test('a title without a size keeps the stored pack columns', function (): void {
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '2.49', name: 'HiPRO Protein Drink Mango'));

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'pack_quantity' => '300.00',
        'pack_unit' => 'ml',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();
    expect((string) $shop->pack_quantity)->toBe('300.00')
        ->and($shop->pack_unit)->toBe('ml');
});

test('a failed check never touches the pack columns', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('<html><body>no metadata</body></html>', 200),
    ]);

    $shop = Shop::factory()->create([
        'url' => 'https://shop.test/p/1',
        'pack_quantity' => '250.00',
        'pack_unit' => 'g',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();
    expect($shop->last_status)->toBe(ScrapeStatus::ParseError)
        ->and((string) $shop->pack_quantity)->toBe('250.00')
        ->and($shop->pack_unit)->toBe('g');
});

test('a host adapter that loses its payload fails the check instead of taking a stray number', function (): void {
    // A Poiesz page stripped of its Nuxt payload but carrying JSON-LD for
    // something else entirely: the recheck must not price that instead.
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Unrelated banner product',
        'offers' => ['@type' => 'Offer', 'price' => '99.99', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'https://webwinkel.poiesz-supermarkten.nl/robots.txt' => Http::response('', 404),
        'https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/278550' => Http::response(
            withJsonLd($json),
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/278550',
        'adapter_key' => 'poiesz',
        'current_price' => '1.99',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();

    expect($shop->last_status)->toBe(ScrapeStatus::ParseError)
        ->and((string) $shop->current_price)->toBe('1.99')
        ->and($shop->last_error)->toBe('poiesz_no_payload');
});

test('a tracked shop whose host never serves its prices is recorded as needs_js', function (): void {
    RateLimiter::clear(ShopFetcher::throttleKey('plus.nl'));

    Http::fake([
        'https://www.plus.nl/robots.txt' => Http::response('', 404),
        'https://www.plus.nl/product/fanta-1500-ml-991700' => Http::response('<html>app shell</html>', 200),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://www.plus.nl/product/fanta-1500-ml-991700',
    ]);

    dispatch_sync(new CheckShopPrice($shop));

    expect(PriceCheck::query()->where('shop_id', $shop->id)->latest('id')->first()?->status)
        ->toBe(ScrapeStatus::NeedsJs);
});

test('a currency mismatch does not overwrite the price', function (): void {
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '9.00', 'GBP'));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '10.00',
        'currency' => 'EUR',
        'consecutive_failures' => 0,
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '10.00'])->save();

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();
    expect((string) $shop->current_price)->toBe('10.00')
        ->and($shop->currency)->toBe('EUR')
        ->and($shop->last_status)->toBe(ScrapeStatus::CurrencyMismatch)
        ->and($shop->consecutive_failures)->toBe(1);
});

test('a currency mismatch fires no drop event and no notification', function (): void {
    Notification::fake();

    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '9.00', 'GBP'));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '10.00',
        'currency' => 'EUR',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '10.00'])->save();

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    expect(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(0);
    Notification::assertNothingSent();
});

test('surrounding whitespace and lowercase in the reported currency do not trigger a mismatch', function (): void {
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '60.00', ' eur '));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '10.00',
        'currency' => 'EUR',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();
    expect($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and((string) $shop->current_price)->toBe('60.00')
        // The column is char(3): the padded value must be normalised before
        // it is stored, not written through as the shop sent it.
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->value('currency'))->toBe('EUR')
        ->and($shop->currency)->toBe('EUR');
});

test('a currency that is not a three-letter code is stored as none, and flags a mismatch', function (): void {
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '60.00', 'Euro'));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '10.00',
        'currency' => 'EUR',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();

    expect($shop->last_status)->toBe(ScrapeStatus::CurrencyMismatch)
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->value('currency'))->toBeNull()
        ->and($shop->currency)->toBe('EUR');
});

test('an empty currency is no signal and does not trigger a mismatch', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $fakeAdapter = new class implements ShopAdapter {
        public function key(): string
        {
            return 'fake-empty-currency';
        }

        public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
        {
            return ExtractionResult::success(new ShopSnapshot(
                title: 'X',
                imageUrl: null,
                price: '9.00',
                currency: '',
                inStock: true,
            ));
        }
    };

    app()->instance(AdapterResolver::class, new AdapterResolver([$fakeAdapter]));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'current_price' => '10.00',
        'currency' => 'EUR',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();
    expect($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and((string) $shop->current_price)->toBe('9.00')
        ->and($shop->currency)->toBe('EUR');
});

/**
 * Put the job on a real database queue. `release()` is a no-op without a
 * queued job context, so the sync driver the suite defaults to cannot
 * exercise the retry at all.
 */
function workQueueOnce(): void
{
    Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
}

function drainHostBudget(string $host = 'shop.test'): void
{
    $perMinute = config()->integer('dipcatch.fetcher.rate_limit_per_minute', 12);
    for ($i = 0; $i < $perMinute; $i++) {
        RateLimiter::hit(ShopFetcher::throttleKey($host));
    }
    Cache::put("dipcatch:robots:{$host}", [], 60);
}

test('a rate-limited job is released back onto the queue instead of failing', function (): void {
    config()->set('queue.default', 'database');
    drainHostBudget();
    Http::fake();

    $shop = Shop::factory()->create(['url' => 'https://shop.test/p/1', 'consecutive_failures' => 0]);
    dispatch(new CheckShopPrice($shop));

    // Two pops. The first leaves attempts at 1, which the old `$tries = 1`
    // still permitted; the failure only lands on the second. The release
    // delay tracks RateLimiter::availableIn, up to 60s plus 5s of jitter, so
    // the clock has to move past that for the job to be available again —
    // and the limiter has to be re-drained, because it decays in 60s.
    workQueueOnce();
    $this->travel(70)->seconds();
    drainHostBudget();
    workQueueOnce();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('a rate-limited cycle writes no check and leaves the failure counter alone', function (): void {
    config()->set('queue.default', 'database');
    drainHostBudget();
    Http::fake();

    $shop = Shop::factory()->create(['url' => 'https://shop.test/p/1', 'consecutive_failures' => 0]);
    dispatch(new CheckShopPrice($shop));

    workQueueOnce();
    $this->travel(70)->seconds();
    drainHostBudget();
    workQueueOnce();

    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(0)
        ->and($shop->fresh()->consecutive_failures)->toBe(0);
});

test('a genuine failure still runs once and is not retried by the deadline', function (): void {
    config()->set('queue.default', 'database');
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('boom', 500),
    ]);

    $shop = Shop::factory()->create(['url' => 'https://shop.test/p/1', 'consecutive_failures' => 0]);
    dispatch(new CheckShopPrice($shop));

    workQueueOnce();
    $this->travel(20)->seconds();
    workQueueOnce();

    // The deadline must not turn a recorded failure into a retry loop: the
    // job wrote its own outcome and left the queue.
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1)
        ->and($shop->fresh()->consecutive_5xx_failures)->toBe(1);
});

test('an exception thrown out of the job fails it once rather than looping', function (): void {
    config()->set('queue.default', 'database');
    // The ah.nl branch runs before any try in handle(), and AhApiSource only
    // catches ConnectionException — so this escapes the job. Without
    // maxExceptions the deadline would retry it for a quarter of an hour.
    Http::fake([
        'https://api.ah.nl/mobile-auth/*' => Http::response(['access_token' => 't'], 200),
        'https://api.ah.nl/mobile-services/*' => fn () => throw new RuntimeException('kaboom'),
    ]);

    $shop = Shop::factory()->create(['url' => 'https://ah.nl/producten/product/wi1/x']);
    dispatch(new CheckShopPrice($shop));

    workQueueOnce();
    $this->travel(20)->seconds();
    workQueueOnce();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a host saturated across the whole release budget records a rate-limited check', function (): void {
    config()->set('queue.default', 'database');
    drainHostBudget();
    Http::fake();

    $shop = Shop::factory()->create(['url' => 'https://shop.test/p/1', 'consecutive_failures' => 0]);
    dispatch(new CheckShopPrice($shop));

    // Five attempts: four release, the fifth gives up and records. Re-drain
    // each time, because the limiter decays inside the travelled window.
    for ($i = 0; $i < 5; $i++) {
        workQueueOnce();
        $this->travel(70)->seconds();
        drainHostBudget();
    }

    $check = PriceCheck::query()->where('shop_id', $shop->id)->sole();

    expect($check->status)->toBe(ScrapeStatus::RateLimited)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        // Accepted trade-off: recording the check ticks the shared failure
        // counter, against failing_after = 3. The alternative is a cycle that
        // leaves no trace at all.
        ->and($shop->fresh()->consecutive_failures)->toBe(1);
});

test('a released job completes normally once the host budget refills', function (): void {
    config()->set('queue.default', 'database');
    drainHostBudget();
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '60.00'));

    $shop = Shop::factory()->create(['url' => 'https://shop.test/p/1']);
    dispatch(new CheckShopPrice($shop));

    workQueueOnce();
    expect(DB::table('jobs')->count())->toBe(1);

    // Let the bucket refill rather than re-draining. This is the whole point
    // of releasing: the next attempt succeeds.
    $this->travel(70)->seconds();
    workQueueOnce();

    $check = PriceCheck::query()->where('shop_id', $shop->id)->sole();

    expect($check->status)->toBe(ScrapeStatus::Ok)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($shop->fresh()->consecutive_failures)->toBe(0);
});

test('a job dispatched with the full recheck jitter still runs', function (): void {
    config()->set('queue.default', 'database');
    Http::fake(fakeJsonLdResponse('shop.test', '/p/1', '60.00'));

    $shop = Shop::factory()->create(['url' => 'https://shop.test/p/1']);

    // RecheckActiveShopsCommand delays every dispatch by up to this much. A
    // wall-clock deadline stamped at dispatch would already be spent by the
    // time the job became available, and the worker would dead-letter it
    // before handle() ran.
    dispatch(new CheckShopPrice($shop))->delay(now()->addSeconds(RecheckJitter::maxSeconds()));

    $this->travel(RecheckJitter::maxSeconds() + 10)->seconds();
    workQueueOnce();

    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('an upstream Retry-After longer than the cap does not strand the job', function (): void {
    config()->set('queue.default', 'database');
    Cache::put('dipcatch:robots:shop.test', [], 3600);
    Http::fake([
        'https://shop.test/p/1' => Http::response('slow down', 429, ['Retry-After' => '3600']),
    ]);

    $shop = Shop::factory()->create(['url' => 'https://shop.test/p/1']);
    dispatch(new CheckShopPrice($shop));

    workQueueOnce();

    // Unclamped this would sit for an hour — past uniqueFor(), so a duplicate
    // could be dispatched alongside it.
    $this->travel(70)->seconds();
    workQueueOnce();

    expect(DB::table('jobs')->where('attempts', '>=', 2)->count())->toBe(1);
});
