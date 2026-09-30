<?php declare(strict_types=1);

use App\Actions\Products\CreateProductWithShop;
use App\Actions\Products\ProductDraft;
use App\Actions\Shops\ShopDraft;
use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Jobs\DiscoverWebShops;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebSearch;
use App\Models\WebShopFinding;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    Cache::flush();
    Queue::fake();
});

function triggerOwner(bool $shopChecks = true): User
{
    $user = User::factory()->create(['shop_checks' => $shopChecks]);
    subscribeUser($user);

    return $user;
}

function shopDraftFor(string $currency = 'EUR'): ShopDraft
{
    return ShopDraft::fromSnapshot(
        snapshot: ['price' => '2.50', 'currency' => $currency, 'in_stock' => true, 'title' => 'Coffee 500 g'],
        url: 'https://shop.test/p/1',
        adapterKey: 'json-ld',
    );
}

/**
 * @param  array{created_at?: DateTimeInterface, currency?: string}  $attributes
 */
function gatedProduct(?User $owner = null, array $attributes = []): Product
{
    $product = Product::factory()->for($owner ?? triggerOwner())->create(['currency' => 'EUR', ...$attributes]);
    Shop::factory()->for($product)->create(['pack_quantity' => '500.00', 'pack_unit' => 'g']);

    return $product->refresh();
}

it('queues discovery when a gated owner creates a euro product, marked queued first', function (): void {
    $product = app(CreateProductWithShop::class)(triggerOwner(), new ProductDraft(title: 'Coffee'), shopDraftFor());

    Queue::assertPushed(DiscoverWebShops::class, fn (DiscoverWebShops $job): bool => $job->productId === (string) $product->id);
    expect(WebDiscovery::query()->find($product->id)?->state)->toBe(WebDiscoveryState::Queued);
});

it('queues nothing for an owner without shop checks, a non-euro product, or without a search key', function (Closure $arrange, string $currency): void {
    /** @var Closure(User): User $arrange */
    $owner = $arrange(triggerOwner());

    app(CreateProductWithShop::class)($owner, new ProductDraft(title: 'Coffee'), shopDraftFor($currency));

    Queue::assertNotPushed(DiscoverWebShops::class);
    expect(WebDiscovery::query()->count())->toBe(0);
})->with([
    'no shop checks' => [function (User $owner): User {
        $owner->forceFill(['shop_checks' => false])->save();

        return $owner;
    }, 'EUR'],
    'not euros' => [fn (User $owner): User => $owner, 'USD'],
    'no search key' => [function (User $owner): User {
        config()->set('services.serper.key', '');

        return $owner;
    }, 'EUR'],
]);

it('queues unfinished runs, then stale findings, then never-searched products, and stops at the search limit', function (): void {
    config()->set('dipcatch.web_discovery.daily_search_limit', 1);
    $owner = triggerOwner();
    $search = WebSearch::query()->create(['query_hash' => 'h', 'query' => 'q', 'results' => [], 'searched_at' => now()]);

    $neverA = gatedProduct($owner, ['created_at' => now()->subDays(3)]);
    $neverB = gatedProduct($owner, ['created_at' => now()->subDays(2)]);

    $unfinished = gatedProduct($owner);
    WebDiscovery::query()->create(['product_id' => $unfinished->id, 'web_search_id' => $search->id, 'search_searched_at' => $search->searched_at, 'state' => WebDiscoveryState::Done]);
    WebShopFinding::query()->create(['product_id' => $unfinished->id, 'url' => 'https://a.test/p', 'url_hash' => 'x', 'host' => 'a.test', 'search_title' => 'x', 'status' => WebFindingStatus::Read, 'fingerprint' => WebShopFinding::fingerprintFor($unfinished)]);

    $stale = gatedProduct($owner, ['created_at' => now()->subMinutes(2)]);
    WebDiscovery::query()->create(['product_id' => $stale->id, 'web_search_id' => $search->id, 'search_searched_at' => $search->searched_at, 'state' => WebDiscoveryState::Done]);
    WebShopFinding::query()->create(['product_id' => $stale->id, 'url' => 'https://a.test/p', 'url_hash' => 'y', 'host' => 'a.test', 'search_title' => 'x', 'status' => WebFindingStatus::Proposed, 'fingerprint' => str_repeat('0', 64)]);

    $refreshedElsewhere = gatedProduct($owner, ['created_at' => now()->subMinute()]);
    WebDiscovery::query()->create(['product_id' => $refreshedElsewhere->id, 'web_search_id' => $search->id, 'search_searched_at' => now()->subDay(), 'state' => WebDiscoveryState::Done]);

    $searchedLongAgo = gatedProduct($owner, ['created_at' => now()->subDays(9)]);
    $oldSearch = WebSearch::query()->create(['query_hash' => 'old', 'query' => 'old', 'results' => [], 'searched_at' => now()->subDays(91)]);
    WebDiscovery::query()->create(['product_id' => $searchedLongAgo->id, 'web_search_id' => $oldSearch->id, 'search_searched_at' => $oldSearch->searched_at, 'state' => WebDiscoveryState::Done]);

    $upToDate = gatedProduct($owner);
    WebDiscovery::query()->create(['product_id' => $upToDate->id, 'web_search_id' => $search->id, 'search_searched_at' => $search->searched_at, 'state' => WebDiscoveryState::Done]);

    $this->artisan('dipcatch:discover-web-shops')->assertSuccessful();

    $queued = Queue::pushed(DiscoverWebShops::class)->map(fn (DiscoverWebShops $job): string => $job->productId)->all();

    // One search a day: the oldest never-searched product goes before an
    // older product whose search is only stale.
    expect($queued)->toBe([(string) $unfinished->id, (string) $stale->id, (string) $refreshedElsewhere->id, (string) $neverA->id])
        ->and($queued)->not->toContain((string) $neverB->id)
        ->and($queued)->not->toContain((string) $searchedLongAgo->id)
        ->and($queued)->not->toContain((string) $upToDate->id);
});

it('queues every product that needs a search when the limit is zero', function (): void {
    config()->set('dipcatch.web_discovery.daily_search_limit', 0);
    $owner = triggerOwner();
    gatedProduct($owner);
    gatedProduct($owner);

    $this->artisan('dipcatch:discover-web-shops')->assertSuccessful();

    Queue::assertPushed(DiscoverWebShops::class, 2);
});

it('spends the search limit on gated products only', function (): void {
    config()->set('dipcatch.web_discovery.daily_search_limit', 1);
    $free = User::factory()->create(['shop_checks' => true]);
    gatedProduct($free, ['created_at' => now()->subDays(5)]);
    gatedProduct(triggerOwner(shopChecks: false), ['created_at' => now()->subDays(4)]);
    gatedProduct(triggerOwner(), ['created_at' => now()->subDays(3), 'currency' => 'USD']);
    $gated = gatedProduct(triggerOwner(), ['created_at' => now()->subDays(2)]);

    $this->artisan('dipcatch:discover-web-shops')->assertSuccessful();

    expect(Queue::pushed(DiscoverWebShops::class)->map(fn (DiscoverWebShops $job): string => $job->productId)->all())->toBe([(string) $gated->id]);
});

it('skips a product made by hand until it has a shop', function (): void {
    $handMade = Product::factory()->for(triggerOwner())->create(['currency' => 'EUR']);

    $this->artisan('dipcatch:discover-web-shops')->assertSuccessful();
    Queue::assertNotPushed(DiscoverWebShops::class);

    Shop::factory()->for($handMade)->create();
    $this->artisan('dipcatch:discover-web-shops')->assertSuccessful();
    Queue::assertPushed(DiscoverWebShops::class, 1);
});

it('is scheduled daily', function (): void {
    // The schedule registers when the console kernel boots.
    Artisan::call('schedule:list');

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'dipcatch:discover-web-shops'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('40 4 * * *');
});
