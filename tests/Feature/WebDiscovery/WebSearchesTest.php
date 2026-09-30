<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\WebSearch;
use App\Models\WebShopFinding;
use App\Services\ShopDiscovery\SerperProvider;
use App\Services\ShopDiscovery\WebResultFilter;
use App\Services\ShopDiscovery\WebSearches;
use App\Support\UrlNormalizer;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    Http::preventStrayRequests();
});

/**
 * @param  list<array{title: string, link: string, snippet?: string}>  $organic
 */
function fakeSerper(array $organic = [], int $status = 200): void
{
    Http::fake([SerperProvider::ENDPOINT => Http::response(['organic' => $organic], $status)]);
}

it('searches once and serves the stored search while it is fresh', function (): void {
    fakeSerper([['title' => 'Coffee beans 1 kg', 'link' => 'https://shop.test/p/1', 'snippet' => 'Beans']]);
    $searches = app(WebSearches::class);

    $first = $searches->forQuery('Coffee beans');
    $second = $searches->forQuery('  coffee   BEANS ');

    expect($first?->id)->toBe($second?->id)
        ->and($first?->results[0]['link'])->toBe('https://shop.test/p/1');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-KEY', 'test-key')
        && $request['q'] === 'Coffee beans' && $request['gl'] === 'nl' && $request['num'] === 10);
});

it('searches again once the stored search is older than the maximum age', function (): void {
    fakeSerper([['title' => 'New', 'link' => 'https://shop.test/p/2']]);
    WebSearch::query()->create(['query_hash' => WebSearch::hashOf('Coffee'), 'query' => 'coffee', 'results' => [], 'searched_at' => now()->subDays(91)]);

    $search = app(WebSearches::class)->forQuery('Coffee');

    expect($search?->results)->toHaveCount(1)
        ->and(WebSearch::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('stops at the daily search limit, also when the next query differs', function (): void {
    config()->set('dipcatch.web_discovery.daily_search_limit', 1);
    fakeSerper([]);
    $searches = app(WebSearches::class);

    expect($searches->forQuery('One'))->not->toBeNull()
        ->and($searches->forQuery('Two'))->toBeNull();
    Http::assertSentCount(1);
});

it('lets one of two workers take the last search of the day', function (): void {
    config()->set('dipcatch.web_discovery.daily_search_limit', 1);
    // Another worker already counted the last search of the day.
    Cache::put('web-discovery:searches:' . now()->toDateString(), 1, now()->endOfDay());
    fakeSerper([]);

    expect(app(WebSearches::class)->forQuery('Other query'))->toBeNull();
    Http::assertNothingSent();
});

it('spends one search when another worker stored it while this one waited for the lock', function (): void {
    fakeSerper([]);
    $hash = WebSearch::hashOf('Shared title');

    // The lock is granted only after the other worker stored the search, so
    // the check inside the lock is what finds it.
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andReturnUsing(function (int $seconds, Closure $callback) use ($hash): mixed {
        WebSearch::query()->create(['query_hash' => $hash, 'query' => 'shared title', 'results' => [], 'searched_at' => now()]);

        return $callback();
    });
    Cache::shouldReceive('lock')->once()->with("web-discovery:search:{$hash}", 60)->andReturn($lock);

    expect(app(WebSearches::class)->forQuery('Shared title'))->not->toBeNull();
    Http::assertNothingSent();
});

it('stores nothing when the provider fails, so the next run tries again', function (): void {
    fakeSerper([], 500);

    expect(app(WebSearches::class)->forQuery('Coffee'))->toBeNull()
        ->and(WebSearch::query()->count())->toBe(0);
});

it('stores nothing when the provider times out', function (): void {
    Http::fake([SerperProvider::ENDPOINT => Http::failedConnection()]);

    expect(app(WebSearches::class)->forQuery('Coffee'))->toBeNull()
        ->and(WebSearch::query()->count())->toBe(0);
});

it('does nothing without a key or when switched off', function (): void {
    fakeSerper([]);
    config()->set('services.serper.key', '');
    expect(app(WebSearches::class)->forQuery('Coffee'))->toBeNull();

    config()->set('services.serper.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', false);
    expect(app(WebSearches::class)->forQuery('Coffee'))->toBeNull();

    Http::assertNothingSent();
});

it('keeps one result per new shop, and drops tracked, non-shop and hidden ones', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['url' => 'https://www.tracked.test/p/1', 'host' => 'tracked.test']);
    $hiddenUrl = 'https://hidden.test/p/1';
    WebShopFinding::query()->create([
        'product_id' => $product->id, 'url' => $hiddenUrl, 'url_hash' => UrlNormalizer::hash(UrlNormalizer::normalize($hiddenUrl)),
        'host' => 'hidden.test', 'search_title' => 'x', 'status' => 'proposed', 'fingerprint' => 'x', 'dismissed_at' => now(),
    ]);

    $search = WebSearch::query()->create(['query_hash' => 'h', 'query' => 'q', 'searched_at' => now(), 'results' => [
        ['title' => 'Best', 'link' => 'https://www.new.test/p/1', 'snippet' => 'a', 'position' => 1],
        ['title' => 'Second on same shop', 'link' => 'https://new.test/p/2', 'snippet' => 'b', 'position' => 2],
        ['title' => 'Tracked', 'link' => 'https://tracked.test/p/9', 'snippet' => '', 'position' => 3],
        ['title' => 'Review', 'link' => 'https://nl.wikipedia.org/wiki/Koffie', 'snippet' => '', 'position' => 4],
        ['title' => 'Rental', 'link' => 'https://www.partyverhuren.nl/nl/koffie', 'snippet' => '', 'position' => 5],
        ['title' => 'Hidden', 'link' => $hiddenUrl, 'snippet' => '', 'position' => 6],
        ['title' => 'Other', 'link' => 'https://other.test/p/1', 'snippet' => 'c', 'position' => 7],
    ]]);

    $kept = WebResultFilter::keep($product->refresh(), $search);

    expect(array_column($kept, 'title'))->toBe(['Best', 'Other'])
        ->and($kept[0]['host'])->toBe('new.test');
});

it('stores nothing when Serper answers without a readable result list', function (array $body): void {
    Http::fake([SerperProvider::ENDPOINT => Http::response($body)]);

    expect(app(WebSearches::class)->forQuery('Coffee'))->toBeNull()
        ->and(WebSearch::query()->count())->toBe(0);
})->with([
    'no organic key' => [['searchParameters' => ['q' => 'Coffee']]],
    'rows without a link' => [['organic' => [['title' => 'Coffee', 'url' => 'https://a.test/p']]]],
]);

it('stores a search that found nothing', function (): void {
    fakeSerper([]);

    expect(app(WebSearches::class)->forQuery('Coffee')?->results)->toBe([]);
});
