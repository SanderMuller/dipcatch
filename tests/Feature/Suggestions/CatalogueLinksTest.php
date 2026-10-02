<?php declare(strict_types=1);

use App\Actions\Shops\NotListedOnline;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Suggestions\SuggestShops;
use App\Enums\ProbeFailure;
use App\Jobs\CheckCatalogueLinks;
use App\Jobs\DiscoverWebShops;
use App\Models\CatalogueLink;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebShopFinding;
use App\Services\Checkjebon\CatalogueLinks;
use App\Services\ShopDiscovery\SerperProvider;
use App\Services\ShopDiscovery\WebSearches;
use App\Services\ShopDiscovery\WebShopDiscovery;
use App\Services\ShopFetcher\FetchResult;
use App\Services\ShopFetcher\ShopFetcher;
use App\Services\Suggestions\ShopSuggestion;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Cache::flush();
    seedChains();
});

function linkCheckProduct(?User $owner = null): Product
{
    $product = Product::factory()->for($owner ?? User::factory()->create())->create(['title' => 'Beemster Extra belegen 48+ plakken', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://kaasshop.test/p/1', 'pack_quantity' => '150.00', 'pack_unit' => 'g']);

    return $product->refresh();
}

/**
 * Dirk's product sitemap: the given ids, padded with filler products up to
 * the size a working sitemap has.
 *
 * @param  list<string>  $ids
 */
function dirkSitemap(array $ids, int $filler = 1000): string
{
    $urls = array_map(static fn (string $id): string => "<url><loc>https://www.dirk.nl/boodschappen/zuivel-kaas/kaas/product-{$id}/{$id}</loc></url>", [...$ids, ...array_map(static fn (int $n): string => (string) (900000 + $n), range(1, $filler))]);

    return '<?xml version="1.0" encoding="utf-8"?><urlset>' . implode('', $urls) . '</urlset>';
}

function runLinkCheck(CheckCatalogueLinks $job): void
{
    $job->handle(app(ShopFetcher::class), app(WebSearches::class), app(WebShopDiscovery::class));
}

/**
 * @return list<string>
 */
function linkCheckChains(Product $product): array
{
    return array_map(static fn (ShopSuggestion $suggestion): string => $suggestion->chain, app(SuggestShops::class)($product, verify: false));
}

it('records which Dirk list products are on dirk.nl, from its sitemap', function (): void {
    seedRow('dirk', 'Beemster Kaas extra belegen 48+ plakken', '150 g', link: '115212');
    seedRow('dirk', 'Ajax Allesreiniger fris', '1.25 l', link: '118130');
    Http::fake([CatalogueLinks::DIRK_SITEMAP => Http::response(dirkSitemap(['115212']))]);

    expect(app(CatalogueLinks::class)->importDirkSitemap())->toBe(['alive' => 1, 'gone' => 1])
        ->and(CatalogueLink::query()->where('external_id', '115212')->sole()->only(['alive', 'url']))
        ->toBe(['alive' => true, 'url' => 'https://www.dirk.nl/boodschappen/zuivel-kaas/kaas/product-115212/115212'])
        ->and(CatalogueLink::query()->where('external_id', '118130')->sole()->alive)->toBeFalse();
});

it('changes nothing when the sitemap is broken, too small, or leaves most of the list without a page', function (int $status, int $filler): void {
    seedRow('dirk', 'Ajax Allesreiniger fris', '1.25 l', link: '118130');
    Http::fake([CatalogueLinks::DIRK_SITEMAP => Http::response(dirkSitemap([], $filler), $status)]);

    $this->artisan('dipcatch:check-catalogue-links')->assertFailed();

    expect(CatalogueLink::query()->count())->toBe(0);
})->with([
    'an error' => [503, 1000],
    'only a few products' => [200, 10],
    // A working sitemap, but none of the list's products is in it.
    'most of the list gone' => [200, 1000],
]);

it('stops suggesting a gone page, offers the chain\'s next row, and links to the live address', function (): void {
    seedRow('dirk', 'Beemster Kaas extra belegen 48+ plakken', '150 g', link: '1001');
    seedRow('dirk', 'Beemster extra belegen plakken 48+', '150 g', link: '1002');
    CatalogueLink::record('dirk', '1001', alive: false);
    CatalogueLink::record('dirk', '1002', alive: true, url: 'https://www.dirk.nl/boodschappen/zuivel-kaas/kaas/beemster/1002');

    $dirk = array_values(array_filter(app(SuggestShops::class)(linkCheckProduct(), verify: false), static fn (ShopSuggestion $suggestion): bool => $suggestion->chain === 'dirk'));

    expect($dirk)->toHaveCount(1)
        ->and($dirk[0]->externalId)->toBe('1002')
        ->and($dirk[0]->url)->toBe('https://www.dirk.nl/boodschappen/zuivel-kaas/kaas/beemster/1002');
});

it('suggests a page again once its gone answer is older than a month', function (): void {
    seedRow('dirk', 'Beemster Kaas extra belegen 48+ plakken', '150 g', link: '1001');
    CatalogueLink::record('dirk', '1001', alive: false);
    CatalogueLink::query()->update(['checked_at' => now()->subDays(CatalogueLink::VALID_DAYS + 1)]);

    expect(linkCheckChains(linkCheckProduct()))->toContain('dirk');
});

it('still suggests a page nobody has checked', function (): void {
    seedRow('jumbo', 'Beemster Extra Belegen Plakken 150 g', null, link: 'beemster-150');

    expect(linkCheckChains(linkCheckProduct()))->toContain('jumbo');
});

it('tells a pasted page the shop does not have, and stops suggesting it', function (): void {
    seedRow('dirk', 'Ajax Allesreiniger fris', '1.25 l', link: '118130');
    $url = 'https://www.dirk.nl/boodschappen/x/x/x/118130';

    $outcome = NotListedOnline::onPage($url, new FetchResult(finalUrl: 'https://www.dirk.nl/helaas', host: 'dirk.nl', html: '<html></html>', statusCode: 200));

    expect($outcome?->errorCode)->toBe(ProbeFailure::NotListedOnline)
        ->and($outcome?->context)->toBe(['shop' => 'Dirk'])
        ->and(CatalogueLink::query()->where('chain', 'dirk')->where('external_id', '118130')->sole()->alive)->toBeFalse()
        ->and(NotListedOnline::onPage($url, new FetchResult(finalUrl: $url, host: 'dirk.nl', html: '', statusCode: 200)))->toBeNull();
});

it('answers a 404 for a list page as not listed, and any other 404 as an error', function (): void {
    seedRow('spar', 'Beemster kaas plakken belegen 48+', '150 Gram', link: 'beemster-150-gram');
    Http::fake(['*' => Http::response('', 404)]);
    $user = User::factory()->create();
    $product = linkCheckProduct($user);

    $listed = app(ProbeShopUrl::class)($product, 'https://www.spar.nl/beemster-150-gram', $user);
    $other = app(ProbeShopUrl::class)($product, 'https://www.spar.nl/iets-anders', $user);

    expect($listed->errorCode)->toBe(ProbeFailure::NotListedOnline)
        ->and($listed->errorCode?->isWorthKeepingAsLink())->toBeFalse()
        ->and($other->errorCode)->toBe(ProbeFailure::HttpError);
});

it('matches a pasted page to its list row with or without www, and a real Dirk address by its id', function (string $url, ?array $row): void {
    seedRow('spar', 'Beemster kaas plakken belegen 48+', '150 Gram', link: 'beemster-kaas-plakken-belegen-48-150-gram-met-een-heel-lange-naam-van-de-winkel-7100100');
    seedRow('dirk', 'Ajax Allesreiniger fris', '1.25 l', link: '118130');

    expect(CatalogueLinks::rowFor($url))->toBe($row);
})->with([
    'spar without www' => ['https://spar.nl/beemster-kaas-plakken-belegen-48-150-gram-met-een-heel-lange-naam-van-de-winkel-7100100', ['spar', 'beemster-kaas-plakken-belegen-48-150-gram-met-een-heel-lange-naam-van-de-winkel-7100100']],
    'dirk with a real path' => ['https://www.dirk.nl/boodschappen/huishoud-huisdieren/schoonmaakmiddelen/ajax-allesreiniger-fris/118130', ['dirk', '118130']],
    'another page on the shop' => ['https://www.spar.nl/iets-anders', null],
    'a dirk page that is no product page' => ['https://www.dirk.nl/acties/118130', null],
    'another shop' => ['https://www.etos.nl/beemster', null],
]);

it('records a list id longer than a short column holds', function (): void {
    $id = str_repeat('beemster-', 10) . '7100100';

    CatalogueLink::record('spar', $id, alive: false);

    expect(CatalogueLink::query()->where('external_id', $id)->exists())->toBeTrue();
});

it('checks a suggested page on a shop that answers 404 for a missing product', function (int $status, ?bool $alive): void {
    Http::fake(['*' => Http::response('<html></html>', $status)]);

    runLinkCheck(new CheckCatalogueLinks('none', [['chain' => 'spar', 'externalId' => 'x-1', 'url' => 'https://www.spar.nl/x-1']], []));

    expect(CatalogueLink::query()->where('external_id', 'x-1')->first()?->alive)->toBe($alive);
})->with([
    'gone' => [404, false],
    'there' => [200, true],
    'a server error says nothing' => [503, null],
]);

it('looks up a gone product on the shop\'s own site, for Pro with AI help on', function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    Queue::fake([DiscoverWebShops::class]);
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = linkCheckProduct($user);
    Http::fake([SerperProvider::ENDPOINT => Http::response(['organic' => [
        ['title' => 'Beemster plakken | Dirk', 'link' => 'https://www.dirk.nl/boodschappen/zuivel-kaas/kaas/beemster/2002'],
        ['title' => 'Beemster bij een ander', 'link' => 'https://www.kaasbaas.test/beemster'],
    ]])]);

    runLinkCheck(new CheckCatalogueLinks((string) $product->id, [], [['chain' => 'dirk', 'name' => 'Beemster Kaas extra belegen 48+ plakken', 'size' => '150 g']]));

    expect(WebShopFinding::query()->where('product_id', $product->id)->pluck('host')->all())->toBe(['dirk.nl']);
    Http::assertSent(fn ($request): bool => $request->url() === SerperProvider::ENDPOINT && $request['q'] === 'site:dirk.nl Beemster Kaas extra belegen 48+ plakken 150 g');
    Queue::assertPushed(DiscoverWebShops::class);
});

it('skips the site lookup when the owner switched AI help off after it was queued', function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    Http::fake();
    $user = User::factory()->create(['shop_checks' => false]);
    subscribeUser($user);
    $product = linkCheckProduct($user);

    runLinkCheck(new CheckCatalogueLinks((string) $product->id, [], [['chain' => 'dirk', 'name' => 'Beemster Kaas extra belegen 48+ plakken', 'size' => '150 g']]));

    Http::assertNothingSent();
});

it('keeps a page found on the shop\'s site when the title search runs after it', function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    Queue::fake([DiscoverWebShops::class]);
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = linkCheckProduct($user);
    // The site search finds Dirk's page; the title search finds only another shop.
    Http::fake([
        SerperProvider::ENDPOINT => fn (Request $request) => Http::response(['organic' => str_starts_with($request->body(), '{"q":"site:dirk.nl')
            ? [['title' => 'Beemster plakken | Dirk', 'link' => 'https://www.dirk.nl/boodschappen/zuivel-kaas/kaas/beemster/2002']]
            : [['title' => 'Beemster bij een ander', 'link' => 'https://www.kaasbaas.test/beemster']]]),
        TypeSafeClient::ENDPOINT => Http::response([], 503),
    ]);

    runLinkCheck(new CheckCatalogueLinks((string) $product->id, [], [['chain' => 'dirk', 'name' => 'Beemster Kaas extra belegen 48+ plakken', 'size' => '150 g']]));
    app(WebShopDiscovery::class)->discover($product->refresh());

    expect(WebShopFinding::query()->where('product_id', $product->id)->pluck('host')->sort()->values()->all())->toBe(['dirk.nl', 'kaasbaas.test']);
});

it('queues a site lookup only for Pro with AI help on', function (bool $pro): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    Queue::fake([CheckCatalogueLinks::class]);
    $user = User::factory()->create(['shop_checks' => true]);

    if ($pro) {
        subscribeUser($user);
    }

    seedRow('dirk', 'Beemster Kaas extra belegen 48+ plakken', '150 g', link: '1001');
    CatalogueLink::record('dirk', '1001', alive: false);

    app(SuggestShops::class)(linkCheckProduct($user));
    app()->terminate();

    $pro
        ? Queue::assertPushed(CheckCatalogueLinks::class, fn (CheckCatalogueLinks $job): bool => $job->toFetch === [] && $job->toSearch !== [] && $job->toSearch[0]['chain'] === 'dirk')
        : Queue::assertNotPushed(CheckCatalogueLinks::class);
})->with([
    'Pro with AI help on' => [true],
    'Free' => [false],
]);
