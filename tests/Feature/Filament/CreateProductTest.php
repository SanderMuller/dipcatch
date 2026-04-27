<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ScraperFixtures;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('scraper.user_agent', 'TestBot/1.0');
    config()->set('scraper.timeout', 5);
    config()->set('scraper.host.min_interval_seconds', 8);
    config()->set('scraper.host.jitter_seconds', 0);
    config()->set('scraper.host.lock_ttl_seconds', 30);
    config()->set('scraper.robots.cache_ttl_seconds', 3600);
    Cache::flush();

    $this->actingAs(User::factory()->create(['default_currency' => 'EUR']));
});

test('create page renders the wizard with two steps', function (): void {
    livewire(CreateProduct::class)
        ->assertOk()
        ->assertSeeText('Find product')
        ->assertSeeText('Selectors & preview');
});

test('happy path: detect → preview → save creates Product + first PriceCheck atomically', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/wireless-headphones' => Http::response(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $page = livewire(CreateProduct::class)
        ->fillForm(['url' => 'https://shop.example.com/wireless-headphones'])
        ->call('runDetect');

    expect($page->instance()->data['price_selector'])->toBe('.product-price')
        ->and($page->instance()->data['title'])->toBe('Acme Wireless Headphones');

    $page->call('runPreview');

    expect($page->instance()->previewData['status'])->toBe(ScrapeStatus::Ok->value)
        ->and($page->instance()->previewData['price'])->toBe('1299.95')
        ->and($page->instance()->previewData['currency'])->toBe('EUR')
        ->and($page->instance()->data['drop_threshold_pct'])->toBe('5');

    $page->call('create')->assertHasNoErrors();

    $product = Product::query()->sole();
    expect($product->title)->toBe('Acme Wireless Headphones')
        ->and($product->price_selector)->toBe('.product-price')
        ->and($product->initial_price)->toBe('1299.95')
        ->and($product->last_price)->toBe('1299.95')
        ->and($product->currency)->toBe('EUR')
        ->and($product->user_id)->toBe(auth()->id())
        ->and(PriceCheck::query()->where('product_id', $product->id)->count())->toBe(1);
});

test('save without preview is blocked', function (): void {
    livewire(CreateProduct::class)
        ->fillForm([
            'url' => 'https://shop.example.com/wireless-headphones',
            'title' => 'Manual title',
            'price_selector' => '.product-price',
            'currency' => 'EUR',
            'drop_threshold_pct' => 10,
            'drop_threshold_abs' => 5,
        ])
        ->call('create');

    expect(Product::query()->count())->toBe(0);
});

test('NeedsJs preview blocks save', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://spa.example.com/product' => Http::response(ScraperFixtures::load('needs_js.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    livewire(CreateProduct::class)
        ->fillForm([
            'url' => 'https://spa.example.com/product',
            'title' => 'Whatever',
            'price_selector' => '.price',
            'currency' => 'EUR',
            'drop_threshold_pct' => 10,
            'drop_threshold_abs' => 5,
        ])
        ->call('runPreview')
        ->call('create');

    expect(Product::query()->count())->toBe(0);
});

test('a stale preview (>5 minutes old) blocks save', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/wireless-headphones' => Http::response(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $page = livewire(CreateProduct::class)
        ->fillForm(['url' => 'https://shop.example.com/wireless-headphones'])
        ->call('runDetect')
        ->call('runPreview');

    // Roll the wall clock forward past the 5-minute TTL.
    $page->set('previewedAt', Carbon::now()->getTimestamp() - 400);

    $page->call('create');

    expect(Product::query()->count())->toBe(0);
});

test('editing url after a successful preview invalidates the preview and blocks save', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/wireless-headphones' => Http::response(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $page = livewire(CreateProduct::class)
        ->fillForm(['url' => 'https://shop.example.com/wireless-headphones'])
        ->call('runDetect')
        ->call('runPreview');

    expect($page->instance()->previewData['status'])->toBe(ScrapeStatus::Ok->value);

    // User edits the URL after the preview succeeded — fingerprint diverges,
    // so save must be rejected to avoid persisting the previous URL's price.
    $page->set('data.url', 'https://shop.example.com/different-page')
        ->call('create');

    expect(Product::query()->count())->toBe(0);
});

test('editing the price selector after preview blocks save', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/wireless-headphones' => Http::response(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $page = livewire(CreateProduct::class)
        ->fillForm(['url' => 'https://shop.example.com/wireless-headphones'])
        ->call('runDetect')
        ->call('runPreview');

    $page->set('data.price_selector', '.changed-selector')
        ->call('create');

    expect(Product::query()->count())->toBe(0);
});

test('failed-preview status (HttpError) blocks save', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/missing' => Http::response('Not found', 404),
    ]);

    livewire(CreateProduct::class)
        ->fillForm([
            'url' => 'https://shop.example.com/missing',
            'title' => 'Missing',
            'price_selector' => '.price',
            'currency' => 'EUR',
            'drop_threshold_pct' => 10,
            'drop_threshold_abs' => 5,
        ])
        ->call('runPreview')
        ->call('create');

    expect(Product::query()->count())->toBe(0);
});
