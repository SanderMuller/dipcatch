<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
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
});

test('owner can edit a product and changes persist', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'price_selector' => '.old-selector',
        'fallback_selectors' => ['.alt'],
    ]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['record' => $product->id])
        ->fillForm([
            'price_selector' => '.new-selector',
            'fallback_selectors' => [['selector' => '.first-alt'], ['selector' => '.second-alt']],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $product->refresh();
    expect($product->price_selector)->toBe('.new-selector')
        ->and($product->fallback_selectors)->toBe(['.first-alt', '.second-alt']);
});

test('fallback_selectors round-trip from DB through Repeater rows and back', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'fallback_selectors' => ['.a', '.b'],
    ]);

    $this->actingAs($user);

    $page = livewire(EditProduct::class, ['record' => $product->id]);

    // Filament Repeater stores rows under stable UUID keys for diffing;
    // we only care about the row contents in order.
    /** @var array<string, array{selector: string}> $rows */
    $rows = $page->instance()->data['fallback_selectors'];
    expect(array_values($rows))
        ->toBe([['selector' => '.a'], ['selector' => '.b']]);
});

test('Re-scrape now fires the scraper, records a price_check, and updates last_price', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/wireless-headphones' => Http::response(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'url' => 'https://shop.example.com/wireless-headphones',
        'price_selector' => '.product-price',
        'last_price' => '999.99',
    ]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['record' => $product->id])
        ->callAction('rescrape');

    $product->refresh();
    expect($product->last_price)->toBe('1299.95')
        ->and($product->priceChecks()->count())->toBe(1)
        ->and($product->priceChecks->first()->status)->toBe(ScrapeStatus::Ok);
});

test('successful re-scrape sets a 1h cooldown; second click is blocked', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/wireless-headphones' => Http::response(ScraperFixtures::load('microdata.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'url' => 'https://shop.example.com/wireless-headphones',
        'price_selector' => '.product-price',
    ]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['record' => $product->id])
        ->callAction('rescrape')
        ->callAction('rescrape');

    expect($product->priceChecks()->count())->toBe(1)
        ->and(RateLimiter::tooManyAttempts(EditProduct::cooldownKey($product->id), maxAttempts: 1))->toBeTrue();
});

test('failed re-scrape does NOT set the cooldown', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'https://shop.example.com/missing' => Http::response('Not found', 404),
    ]);

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'url' => 'https://shop.example.com/missing',
        'price_selector' => '.price',
    ]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['record' => $product->id])
        ->callAction('rescrape');

    expect(RateLimiter::tooManyAttempts(EditProduct::cooldownKey($product->id), maxAttempts: 1))->toBeFalse()
        ->and($product->priceChecks()->where('status', ScrapeStatus::HttpError)->count())->toBe(1);
});

test('view page renders for owner', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();

    $this->actingAs($user);

    $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk();
});

test('bulk pause flips active=false; bulk resume flips it back', function (): void {
    $user = User::factory()->create();
    $products = Product::factory()->count(3)->for($user)->create(['active' => true]);

    $this->actingAs($user);

    livewire(ListProducts::class)
        ->selectTableRecords($products->pluck('id')->all())
        ->callAction(TestAction::make('pause')->table()->bulk());

    expect(Product::query()->where('active', true)->count())->toBe(0);

    livewire(ListProducts::class)
        ->selectTableRecords($products->pluck('id')->all())
        ->callAction(TestAction::make('resume')->table()->bulk());

    expect(Product::query()->where('active', true)->count())->toBe(3);
});
