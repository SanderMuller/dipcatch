<?php declare(strict_types=1);

use App\Jobs\PrewarmShopSearches;
use App\Livewire\Products\AddProductWizard;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebSearch;
use App\Services\ShopDiscovery\KlarnaPageSearch;
use App\Services\ShopDiscovery\SerperProvider;
use App\Services\ShopDiscovery\WebSearches;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    config()->set('dipcatch.web_discovery.klarna_leads', true);
});

function shopCheckUser(): User
{
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);

    return $user;
}

function previewPage(string $currency = 'EUR'): void
{
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Ecodor UF2000 urinegeur verwijderaar 2 x 1 liter',
        'image' => 'https://shop.example.com/img.jpg',
        'offers' => ['@type' => 'Offer', 'price' => '24.99', 'priceCurrency' => $currency, 'availability' => 'https://schema.org/InStock'],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ]);
}

it('starts the shop search at the preview, before the product is saved', function (): void {
    Queue::fake();
    previewPage();
    $this->actingAs(shopCheckUser());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview');

    Queue::assertPushed(PrewarmShopSearches::class, fn (PrewarmShopSearches $job): bool => $job->title === 'Ecodor UF2000 urinegeur verwijderaar 2 x 1 liter');
    expect(Product::query()->count())->toBe(0);
});

it('searches nothing at the preview where discovery would not search after the save', function (Closure $arrange): void {
    Queue::fake();
    $arrange();

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview');

    Queue::assertNotPushed(PrewarmShopSearches::class);
})->with([
    'an account without the AI shop check' => [function (): void {
        previewPage();
        test()->actingAs(User::factory()->create());
    }],
    'a free account that left the AI shop check on' => [function (): void {
        previewPage();
        test()->actingAs(User::factory()->create(['shop_checks' => true]));
    }],
    'a price in another currency' => [function (): void {
        previewPage('USD');
        test()->actingAs(shopCheckUser());
    }],
    'a page the account already tracks' => [function (): void {
        previewPage();
        $user = shopCheckUser();
        Shop::factory()->for(Product::factory()->for($user)->create())->create(['url' => 'https://shop.example.com/p/1']);
        test()->actingAs($user);
    }],
]);

it('lets one account search ahead only so often, as each search is paid', function (): void {
    Queue::fake();
    // Mid-morning, so the hours below stay on one day.
    $this->travelTo(now()->setTime(9, 0));
    $user = shopCheckUser();

    foreach (range(1, PrewarmShopSearches::PER_HOUR + 1) as $preview) {
        PrewarmShopSearches::dispatchFor($user, "Product {$preview}", 'EUR');
    }

    Queue::assertPushed(PrewarmShopSearches::class, PrewarmShopSearches::PER_HOUR);

    PrewarmShopSearches::dispatchFor(shopCheckUser(), 'Another account', 'EUR');

    Queue::assertPushed(PrewarmShopSearches::class, PrewarmShopSearches::PER_HOUR + 1);

    foreach (range(1, 3) as $hour) {
        $this->travel(1)->hour();
        foreach (range(1, PrewarmShopSearches::PER_HOUR) as $preview) {
            PrewarmShopSearches::dispatchFor($user, "Product {$hour}-{$preview}", 'EUR');
        }
    }

    // Three more hours fill the day's budget, not three more hourly ones.
    Queue::assertPushed(PrewarmShopSearches::class, PrewarmShopSearches::PER_DAY + 1);
});

it('stores the shop search and the Klarna search, which discovery then reuses for the same title', function (): void {
    Http::preventStrayRequests();
    Http::fake([SerperProvider::ENDPOINT => Http::response(['organic' => [['title' => 'Ecodor 2 x 1 liter', 'link' => 'https://shop.test/p/1']]])]);

    new PrewarmShopSearches('Ecodor UF2000')->handle(app(WebSearches::class));

    expect(WebSearch::query()->pluck('query')->sort()->values()->all())
        ->toBe(['ecodor uf2000', mb_strtolower(KlarnaPageSearch::queryFor('Ecodor UF2000', 'nl'))]);

    app(WebSearches::class)->forQuery('Ecodor UF2000', 'nl');
    app(WebSearches::class)->lookUp(KlarnaPageSearch::queryFor('Ecodor UF2000', 'nl'), 'nl');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['q'] === 'Ecodor UF2000');
});

it('searches for the account\'s country, and skips the Klarna search where DipCatch does not search Klarna', function (): void {
    Queue::fake();
    $user = shopCheckUser();
    $user->forceFill(['country' => 'fr'])->save();

    PrewarmShopSearches::dispatchFor($user, 'Ecodor UF2000', 'EUR');

    Queue::assertPushed(PrewarmShopSearches::class, fn (PrewarmShopSearches $job): bool => $job->country === 'fr');

    Http::preventStrayRequests();
    Http::fake([SerperProvider::ENDPOINT => Http::response(['organic' => [['title' => 'Ecodor 2 x 1 liter', 'link' => 'https://shop.test/p/1']]])]);

    new PrewarmShopSearches('Ecodor UF2000', 'fr')->handle(app(WebSearches::class));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['gl'] === 'fr');
});

it('skips the Klarna search when the open search already found the Klarna page', function (): void {
    Http::preventStrayRequests();
    Http::fake([SerperProvider::ENDPOINT => Http::response(['organic' => [['title' => 'Ecodor UF2000 - Klarna', 'link' => 'https://www.klarna.com/nl/shopping/pl/cl1/123/Ecodor-UF2000/']]])]);

    new PrewarmShopSearches('Ecodor UF2000')->handle(app(WebSearches::class));

    Http::assertSentCount(1);
});
