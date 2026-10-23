<?php declare(strict_types=1);

use App\Models\User;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    seedChains();
    // A chain counts as listed once it has fresh rows.
    seedRow('spar', 'Demo pindakaas', '650 g');
    seedRow('hoogvliet', 'Demo pindakaas', '650 g');
    $this->actingAs(User::factory()->create());
});

function photoTestPage(): string
{
    return 'https://www.spar.nl/demo-pindakaas-650-g-1234567/';
}

function photoLink(?string $url = null): string
{
    return URL::signedRoute('app.name-search.photo', ['url' => $url ?? photoTestPage()]);
}

function sparPageFetches(): int
{
    return count(Http::recorded(static fn (Request $request): bool => $request->url() === photoTestPage()));
}

test('an unsigned link is refused', function (): void {
    Http::fake();

    $this->get(route('app.name-search.photo', ['url' => photoTestPage()]))->assertForbidden();

    Http::assertNothingSent();
});

test('a signed link to a shop the name search does not list is not fetched', function (string $url): void {
    Http::fake();

    $this->get(photoLink($url))->assertNotFound();

    Http::assertNothingSent();
})->with([
    'any other shop' => 'https://shop.example.com/p/1',
    'a supermarket DipCatch cannot track' => 'https://www.hoogvliet.com/product/demo-pindakaas',
    'a local address' => 'http://127.0.0.1/admin',
]);

test('a page photo redirects to its thumbnail, and the next request reads it from the cache', function (): void {
    Http::fake(fakeJsonLdOffer(photoTestPage()));

    $this->get(photoLink())->assertRedirect('https://shop.example.com/img.jpg');
    $this->get(photoLink())->assertRedirect('https://shop.example.com/img.jpg');

    expect(sparPageFetches())->toBe(1);
});

test('a page without a photo answers an empty pixel and is not fetched again that day', function (): void {
    Http::fake([
        'https://www.spar.nl/robots.txt' => Http::response('', 404),
        photoTestPage() => Http::response('<html><head><title>Demo</title></head><body></body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $this->get(photoLink())->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->get(photoLink())->assertOk();

    expect(sparPageFetches())->toBe(1);
});

test('a shop that fails for a moment is asked again, and its failure is not kept as the host’s', function (): void {
    Http::fake([
        'https://www.spar.nl/robots.txt' => Http::response('', 404),
        photoTestPage() => Http::response('', 503),
    ]);

    $this->get(photoLink())->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->get(photoLink())->assertOk();

    expect(sparPageFetches())->toBeGreaterThan(1)
        ->and(DB::table('host_fetch_failures')->count())->toBe(0);
});

test('a host with less than half its fetches left this minute is not fetched for a photo', function (): void {
    Http::fake(fakeJsonLdOffer(photoTestPage()));

    foreach (range(1, intdiv(Config::integer('dipcatch.fetcher.rate_limit_per_minute'), 2)) as $fetch) {
        RateLimiter::hit(ShopFetcher::throttleKey('spar.nl'));
    }

    $this->get(photoLink())->assertOk()->assertHeader('Content-Type', 'image/gif');

    expect(sparPageFetches())->toBe(0);
});
