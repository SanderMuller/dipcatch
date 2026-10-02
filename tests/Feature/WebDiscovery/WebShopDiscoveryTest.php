<?php declare(strict_types=1);

use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Jobs\CheckWebFindings;
use App\Jobs\DiscoverWebShops;
use App\Jobs\ReadWebFinding;
use App\Models\HiddenShop;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebShopFinding;
use App\Services\ShopDiscovery\SerperProvider;
use App\Services\ShopDiscovery\WebPageReads;
use App\Services\ShopDiscovery\WebSecondCheck;
use App\Services\ShopDiscovery\WebShopDiscovery;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    // The Klarna steps have their own tests (KlarnaDiscoveryTest).
    config()->set('dipcatch.web_discovery.klarna_leads', false);
    config()->set('dipcatch.shop_checks.accept_from', 0.6);
    Cache::flush();
    Http::preventStrayRequests();
});

function discoveryProduct(string $title = 'Coffee beans 1 kg', string $gtin = '8711000000007'): Product
{
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);

    $product = Product::factory()->for($user)->create(['title' => $title, 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://tracked.test/p/1', 'host' => 'tracked.test', 'pack_quantity' => '1000.00', 'pack_unit' => 'g', 'gtin' => $gtin]);

    return $product->refresh()->load('user', 'shops');
}

/** A product page, with a barcode and body text when given. */
function discoveryPage(string $title, string $price = '17.49', ?string $gtin = null, string $body = ''): string
{
    $json = json_encode(array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $title,
        'gtin13' => $gtin,
        'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
    ]), JSON_THROW_ON_ERROR);

    return "<html><head><script type=\"application/ld+json\">{$json}</script></head><body>{$body}</body></html>";
}

/**
 * Fakes Serper, the shop pages and Jev. `$chanceFor` gives Jev's chance per
 * candidate; by default 0.9, or 0.1 for a title with "wrong". Null leaves the
 * candidate out of the answer.
 *
 * @param  list<array{title: string, link: string, snippet?: string}>  $organic
 * @param  array<string, mixed>  $pages
 * @param  (Closure(array<string, string>): ?float)|null  $chanceFor
 */
function fakeDiscovery(array $organic, array $pages, ?Closure $chanceFor = null, bool $jevDown = false): void
{
    $chanceFor ??= static fn (array $candidate): float => str_contains(mb_strtolower(is_string($candidate['title'] ?? null) ? $candidate['title'] : ''), 'wrong') ? 0.1 : 0.9;
    $robots = [];

    foreach (array_keys($pages) as $url) {
        $robots[parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/robots.txt'] = Http::response('', 404);
    }

    Http::preventStrayRequests();
    Http::fake([
        SerperProvider::ENDPOINT => Http::response(['organic' => $organic]),
        TypeSafeClient::ENDPOINT => function (Request $request) use ($chanceFor, $jevDown) {
            if ($jevDown) {
                return Http::response([], 500);
            }

            $answers = [];

            foreach (requestedCandidates($request) as $key => $candidate) {
                $chance = $chanceFor($candidate);

                if ($chance !== null) {
                    $answers[$key] = ['noul' => $chance];
                }
            }

            return Http::response(['answers' => $answers]);
        },
        ...$robots,
        ...$pages,
    ]);
}

function htmlPage(string $html, int $status = 200): Closure
{
    return static fn () => Http::response($html, $status, ['Content-Type' => 'text/html']);
}

/**
 * The candidates one same-product request asked about, keyed as it keyed them.
 *
 * @return array<string, array<string, string>>
 */
function requestedCandidates(Request $request): array
{
    $questions = $request->data()['questions'] ?? null;
    $candidates = [];

    foreach (is_array($questions) ? $questions : [] as $key => $question) {
        $candidate = is_array($question) && is_array($question['instructions'] ?? null) ? ($question['instructions']['candidate'] ?? null) : null;

        if (! is_array($candidate)) {
            continue;
        }

        $fields = [];

        foreach ($candidate as $field => $value) {
            if (is_scalar($value)) {
                $fields[(string) $field] = (string) $value;
            }
        }

        $candidates[(string) $key] = $fields;
    }

    return $candidates;
}

/**
 * Every same-product request sent, as its candidates.
 *
 * @return list<array<string, array<string, string>>>
 */
function jevCandidates(): array
{
    return Http::recorded(fn (Request $request): bool => $request->url() === TypeSafeClient::ENDPOINT)
        ->map(fn (array $pair): array => requestedCandidates($pair[0]))
        ->values()
        ->all();
}

function serperCalls(): int
{
    return Http::recorded(fn (Request $request): bool => $request->url() === SerperProvider::ENDPOINT)->count();
}

function findingAt(Product $product, string $host): WebShopFinding
{
    return WebShopFinding::query()->where('product_id', $product->id)->where('host', $host)->sole();
}

function discover(Product $product): void
{
    app(WebShopDiscovery::class)->discover($product->refresh()->load('user', 'shops'));
}

it('finds a shop, reads its page and proposes it after two checks', function (): void {
    $product = discoveryProduct();
    fakeDiscovery(
        [['title' => 'Coffee beans 1kg — Koffiehenk', 'link' => 'https://koffiehenk.test/coffee-1kg', 'snippet' => 'Beans in a 1 kg bag']],
        ['https://koffiehenk.test/coffee-1kg' => htmlPage(discoveryPage('Coffee beans'))],
    );

    discover($product);

    $finding = findingAt($product, 'koffiehenk.test');
    expect($finding->status)->toBe(WebFindingStatus::Proposed)
        ->and($finding->first_chance)->toBe(0.9)
        ->and($finding->second_chance)->toBe(0.9)
        ->and($finding->page_title)->toBe('Coffee beans')
        ->and($finding->page_price)->toBe('17.49')
        ->and($finding->add_url)->toBe('https://koffiehenk.test/coffee-1kg')
        ->and(WebShopFinding::shownFor($product->refresh())->pluck('id')->all())->toBe([$finding->id])
        ->and(WebDiscovery::query()->find($product->id)?->state)->toBe(WebDiscoveryState::Done);

    [$first, $second] = jevCandidates();
    expect(array_first($first))->toMatchArray(['shop' => 'koffiehenk.test', 'title' => 'Coffee beans 1kg — Koffiehenk', 'snippet' => 'Beans in a 1 kg bag'])
        ->and(array_first($second))->toMatchArray(['title' => 'Coffee beans', 'listing_title' => 'Coffee beans 1kg — Koffiehenk', 'price' => '17.49 EUR']);
});

it('reads only the results that pass the first check, up to the read cap', function (): void {
    config()->set('dipcatch.web_discovery.max_reads_per_product', 1);
    $product = discoveryProduct();
    fakeDiscovery(
        [
            ['title' => 'Coffee beans best', 'link' => 'https://a.test/p'],
            ['title' => 'Wrong tea', 'link' => 'https://b.test/p'],
            ['title' => 'Coffee beans second', 'link' => 'https://c.test/p'],
        ],
        ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))],
        static fn (array $candidate): float => match (true) {
            str_contains($candidate['title'], 'best') => 0.9,
            str_contains($candidate['title'], 'second') => 0.8,
            str_contains($candidate['title'], 'Wrong') => 0.1,
            default => 0.9,
        },
    );

    discover($product);

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Proposed)
        ->and(findingAt($product, 'b.test')->status)->toBe(WebFindingStatus::Rejected)
        ->and(findingAt($product, 'c.test')->status)->toBe(WebFindingStatus::Rejected)
        ->and(findingAt($product, 'c.test')->failure)->toBe('read_cap');
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://c.test/p'));
});

it('declines a page the second check rates low', function (): void {
    $product = discoveryProduct();
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://a.test/p']],
        ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans 250 g wrong pack'))],
    );

    discover($product);

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Declined)
        ->and(WebShopFinding::shownFor($product)->all())->toBeEmpty();
});

it('marks a page it cannot read as unreadable, with the reason', function (int $status, string $html, string $failure): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage($html, $status)]);

    discover($product);

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Unreadable)
        ->and(findingAt($product, 'a.test')->failure)->toBe($failure)
        ->and(WebDiscovery::query()->find($product->id)?->state)->toBe(WebDiscoveryState::Done);
})->with([
    'not found' => [404, 'gone', 'http_error'],
    'blocked' => [403, 'Access denied', 'blocked'],
    'no product data' => [200, '<html><body>A category page</body></html>', 'extraction_failed'],
]);

it('retries a rate-limited read later, and gives up after the last try', function (): void {
    config()->set('dipcatch.web_discovery.read_attempts', 2);
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => Http::response('slow down', 429, ['Retry-After' => '120'])]);
    Queue::fake();

    discover($product);
    $finding = findingAt($product, 'a.test');

    $delay = app(WebPageReads::class)->read($finding);
    $finding->refresh();

    expect($delay)->toBe(120)
        ->and($finding->status)->toBe(WebFindingStatus::PendingRead)
        ->and($finding->attempts)->toBe(1)
        ->and($finding->next_attempt_at?->isFuture())->toBeTrue()
        ->and(WebDiscovery::query()->find($product->id)?->state)->toBe(WebDiscoveryState::Running);

    Cache::flush();
    expect(app(WebPageReads::class)->read($finding->load('product.user', 'product.shops')))->toBeNull()
        ->and($finding->refresh()->status)->toBe(WebFindingStatus::Unreadable)
        ->and($finding->failure)->toBe('host_rate_limited');
});

it('proposes a page as soon as it is read, while another shop still waits to retry its read', function (): void {
    $product = discoveryProduct();
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://slow.test/p'], ['title' => 'Coffee beans', 'link' => 'https://quick.test/p']],
        ['https://slow.test/p' => Http::response('slow down', 429, ['Retry-After' => '120']), 'https://quick.test/p' => htmlPage(discoveryPage('Coffee beans'))],
    );

    discover($product);

    expect(findingAt($product, 'quick.test')->status)->toBe(WebFindingStatus::Proposed)
        ->and(WebShopFinding::shownFor($product->refresh())->pluck('host')->all())->toBe(['quick.test'])
        ->and(findingAt($product, 'slow.test')->status)->toBe(WebFindingStatus::PendingRead)
        ->and(WebDiscovery::query()->find($product->id)?->state)->toBe(WebDiscoveryState::Running);
});

it('checks the pages of an older product once every read is done, as in the daily run, to keep its Jev calls to one', function (): void {
    $product = discoveryProduct();
    $this->travel(1)->day();
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://slow.test/p'], ['title' => 'Coffee beans', 'link' => 'https://quick.test/p']],
        ['https://slow.test/p' => Http::response('slow down', 429, ['Retry-After' => '120']), 'https://quick.test/p' => htmlPage(discoveryPage('Coffee beans'))],
    );

    discover($product);

    expect(findingAt($product, 'quick.test')->status)->toBe(WebFindingStatus::Read)
        ->and(jevCandidates())->toHaveCount(1);
});

it('waits for every read again once a new product has had its early checks', function (): void {
    $product = discoveryProduct();
    Cache::put("web-discovery:early-check:{$product->id}", 4, 600);
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://slow.test/p'], ['title' => 'Coffee beans', 'link' => 'https://quick.test/p']],
        ['https://slow.test/p' => Http::response('slow down', 429, ['Retry-After' => '120']), 'https://quick.test/p' => htmlPage(discoveryPage('Coffee beans'))],
    );

    discover($product);

    expect(findingAt($product, 'quick.test')->status)->toBe(WebFindingStatus::Read);
});

it('proposes pages one by one again once a shop is added to an older product', function (): void {
    $product = discoveryProduct();
    $this->travel(1)->day();
    Shop::factory()->for($product)->create(['url' => 'https://second.test/p/1', 'host' => 'second.test', 'pack_quantity' => '1000.00', 'pack_unit' => 'g']);
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://slow.test/p'], ['title' => 'Coffee beans', 'link' => 'https://quick.test/p']],
        ['https://slow.test/p' => Http::response('slow down', 429, ['Retry-After' => '120']), 'https://quick.test/p' => htmlPage(discoveryPage('Coffee beans'))],
    );

    discover($product);

    expect(findingAt($product, 'quick.test')->status)->toBe(WebFindingStatus::Proposed)
        ->and(findingAt($product, 'slow.test')->status)->toBe(WebFindingStatus::PendingRead);
});

it('waits the fallback delay when a temporary failure names none', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => Http::response('down', 503)]);
    Queue::fake();

    discover($product);

    expect(app(WebPageReads::class)->read(findingAt($product, 'a.test')))->toBe(60);
});

it('rejects a page that redirects to a shop the product tracks', function (): void {
    $product = discoveryProduct();
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://a.test/p']],
        [
            'https://a.test/p' => Http::response('', 302, ['Location' => 'https://tracked.test/other']),
            'https://tracked.test/robots.txt' => Http::response('', 404),
            'https://tracked.test/other' => htmlPage(discoveryPage('Coffee beans')),
        ],
    );

    discover($product);

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Rejected)
        ->and(findingAt($product, 'a.test')->failure)->toBe('tracked_host')
        ->and(findingAt($product, 'a.test')->served_host)->toBe('tracked.test');
});

it('skips a shop its owner hid, in the search results and after a redirect', function (): void {
    $product = discoveryProduct();
    $owner = $product->user()->sole();
    HiddenShop::hide($owner, 'hidden.test');
    HiddenShop::hide($owner, 'moved.test');
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://www.hidden.test/p'], ['title' => 'Coffee beans', 'link' => 'https://a.test/p']],
        [
            'https://a.test/p' => Http::response('', 302, ['Location' => 'https://shop.moved.test/other']),
            'https://shop.moved.test/robots.txt' => Http::response('', 404),
            'https://shop.moved.test/other' => htmlPage(discoveryPage('Coffee beans')),
        ],
    );

    discover($product);

    expect(WebShopFinding::query()->where('host', 'hidden.test')->exists())->toBeFalse()
        ->and(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Rejected)
        ->and(findingAt($product, 'a.test')->failure)->toBe('hidden_shop');
});

it('proposes a page with the tracked barcode without a second Jev call', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee', gtin: '8711000000007'))]);

    discover($product);

    $finding = findingAt($product, 'a.test');
    expect($finding->status)->toBe(WebFindingStatus::Proposed)
        ->and($finding->matched_gtin)->toBe('8711000000007')
        ->and(jevCandidates())->toHaveCount(1);
});

it('rejects a page whose price is not a consumer price', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans', body: '<p>€ 17,49 excl. BTW</p>'))]);

    discover($product);

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Rejected)
        ->and(findingAt($product, 'a.test')->failure)->toBe('not_a_consumer_price');
});

it('keeps the findings for the next run when Jev is down, and resumes without a new search', function (): void {
    $product = discoveryProduct();
    $organic = [['title' => 'Coffee beans', 'link' => 'https://a.test/p']];
    fakeDiscovery($organic, ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))], jevDown: true);

    discover($product);

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::New)
        ->and(WebDiscovery::query()->find($product->id)?->state)->toBe(WebDiscoveryState::Running);

    Http::swap(new Factory());
    fakeDiscovery($organic, ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);
    discover($product);

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Proposed)
        ->and(serperCalls())->toBe(0);
});

it('keeps a read page for the next day when the second check fails, without reading it again', function (): void {
    $product = discoveryProduct();
    $organic = [['title' => 'Coffee beans', 'link' => 'https://a.test/p']];
    $pages = ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))];
    $calls = 0;
    fakeDiscovery($organic, $pages, static function (array $candidate) use (&$calls): ?float {
        $calls++;

        // The first check answers; the second does not.
        return $calls === 1 ? 0.9 : null;
    });

    discover($product);
    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Read);

    Http::swap(new Factory());
    fakeDiscovery($organic, $pages);
    discover($product);

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Proposed);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://a.test/p');
});

it('leaves a finding the first check did not answer for the next run', function (): void {
    $product = discoveryProduct();
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://a.test/p'], ['title' => 'Coffee unanswered', 'link' => 'https://b.test/p']],
        ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))],
        static fn (array $candidate): ?float => str_contains($candidate['title'], 'unanswered') ? null : 0.9,
    );

    discover($product);

    expect(findingAt($product, 'b.test')->status)->toBe(WebFindingStatus::New)
        ->and(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Proposed);
});

it('spends one search and one first check when discovery runs twice', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);

    discover($product);
    discover($product);

    expect(serperCalls())->toBe(1)
        ->and(jevCandidates())->toHaveCount(2);
});

it('starts over when the search is refreshed, by this or by another product', function (): void {
    $first = discoveryProduct('Coffee beans 1 kg');
    $second = discoveryProduct('Coffee beans 1 kg');
    $pages = ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans')), 'https://b.test/p' => htmlPage(discoveryPage('Coffee beans'))];
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p'], ['title' => 'Coffee beans', 'link' => 'https://b.test/p']], $pages);
    discover($first);
    discover($second);

    $this->travel(91)->days();
    Http::swap(new Factory());
    // The refreshed search no longer lists a.test.
    fakeDiscovery([['title' => 'Coffee beans new title', 'link' => 'https://b.test/p']], $pages);

    discover($first);
    expect(WebShopFinding::query()->where('product_id', $first->id)->pluck('host')->all())->toBe(['b.test'])
        ->and(findingAt($first, 'b.test')->search_title)->toBe('Coffee beans new title')
        ->and(findingAt($first, 'b.test')->generation)->toBe(1);

    // The second product reuses the refreshed search, and starts over too.
    discover($second);
    expect(serperCalls())->toBe(1)
        ->and(WebShopFinding::query()->where('product_id', $second->id)->pluck('host')->all())->toBe(['b.test'])
        ->and(findingAt($second, 'b.test')->generation)->toBe(1);
});

it('starts over when the product gets a new pack size or loses a checked barcode', function (Closure $change): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);
    discover($product);
    expect(findingAt($product, 'a.test')->generation)->toBe(0);

    $change($product);
    discover($product);

    expect(findingAt($product, 'a.test')->generation)->toBe(1)
        ->and(serperCalls())->toBe(1);
})->with([
    'new pack size' => [fn (Product $product) => Shop::factory()->for($product)->create(['host' => 'other.test', 'pack_quantity' => '500.00', 'pack_unit' => 'g'])],
    'barcode corrected' => [fn (Product $product) => $product->shops()->update(['gtin' => '8711000000090'])],
]);

it('drops a late write for a finding that started over meanwhile', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);
    Queue::fake();
    discover($product);

    $stale = findingAt($product, 'a.test')->load('product.user', 'product.shops');
    // A start-over lands while this read is on its way.
    WebShopFinding::query()->whereKey($stale->id)->update(['generation' => 5]);

    app(WebPageReads::class)->read($stale);

    expect($stale->refresh()->status)->toBe(WebFindingStatus::PendingRead)
        ->and($stale->read_at)->toBeNull();
});

it('keeps a Hide that lands during a run', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);
    Queue::fake();
    discover($product);

    $finding = findingAt($product, 'a.test')->load('product.user', 'product.shops');
    WebShopFinding::query()->whereKey($finding->id)->update(['dismissed_at' => now()]);

    app(WebPageReads::class)->read($finding);
    app(WebSecondCheck::class)->check($product->refresh()->load('user', 'shops'));

    expect($finding->refresh()->dismissed_at)->not->toBeNull()
        ->and($finding->status)->toBe(WebFindingStatus::Proposed);
});

it('claims each read finding once, so a second check never sends it twice', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);
    Queue::fake();
    discover($product);
    app(WebPageReads::class)->read(findingAt($product, 'a.test')->load('product.user', 'product.shops'));

    // Another check job has claimed it already.
    WebShopFinding::query()->where('product_id', $product->id)->update(['status' => WebFindingStatus::Checking]);
    app(WebSecondCheck::class)->check($product->refresh()->load('user', 'shops'));

    expect(jevCandidates())->toHaveCount(1)
        ->and(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Checking);
});

it('counts first and second checks apart, so a spent first-check budget still finishes read pages', function (): void {
    config()->set('dipcatch.shop_checks.daily_limit_per_user', 1);
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);

    discover($product);

    // One first check and one second check, each within its own limit of one.
    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Proposed);
});

it('does nothing for a product that is not in euros or whose owner has no shop checks', function (Closure $setup): void {
    $product = discoveryProduct();
    $setup($product);
    fakeDiscovery([], []);

    discover($product);

    expect(serperCalls())->toBe(0)
        ->and(WebDiscovery::query()->find($product->id)?->state)->toBe(WebDiscoveryState::Done);
})->with([
    'not euros' => [fn (Product $product) => $product->forceFill(['currency' => 'USD'])->save()],
    'no shop checks' => [fn (Product $product) => $product->user?->forceFill(['shop_checks' => false])->save()],
]);

it('states its tries and gives a crashed read up as unreadable', function (): void {
    config()->set('dipcatch.web_discovery.read_attempts', 3);
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], []);
    Queue::fake();
    discover($product);
    $finding = findingAt($product, 'a.test');

    $job = new ReadWebFinding($finding->id);
    $job->failed(new RuntimeException('killed'));

    expect($job->tries())->toBe(3)
        ->and($job->uniqueFor())->toBeGreaterThan(3 * 900)
        ->and($finding->refresh()->status)->toBe(WebFindingStatus::Unreadable)
        ->and($finding->failure)->toBe('worker_failed: RuntimeException');
});

it('queues the reads, then the second check, as jobs', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);
    Queue::fake();

    new DiscoverWebShops((string) $product->id)->handle(app(WebShopDiscovery::class));
    Queue::assertPushed(ReadWebFinding::class, 1);

    new ReadWebFinding(findingAt($product, 'a.test')->id)->handle(app(WebPageReads::class));
    Queue::assertPushed(CheckWebFindings::class, 1);
    // A moment's wait, so reads that end close together share one Jev call.
    Queue::assertPushed(CheckWebFindings::class, fn (CheckWebFindings $job): bool => $job->delay === CheckWebFindings::GATHER_SECONDS);
});

it('counts an early check when it starts, so reads it gathered while queued use one of the early checks', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p'], ['title' => 'Coffee beans', 'link' => 'https://b.test/p']], [
        'https://a.test/p' => htmlPage(discoveryPage('Coffee beans')),
        'https://b.test/p' => htmlPage(discoveryPage('Coffee beans')),
    ]);
    Queue::fake();
    new DiscoverWebShops((string) $product->id)->handle(app(WebShopDiscovery::class));

    new ReadWebFinding(findingAt($product, 'a.test')->id)->handle(app(WebPageReads::class));
    new ReadWebFinding(findingAt($product, 'b.test')->id)->handle(app(WebPageReads::class));

    expect(Cache::integer("web-discovery:early-check:{$product->id}", 0))->toBe(0);

    Queue::pushed(CheckWebFindings::class)->first()->handle(app(WebSecondCheck::class));

    expect(Cache::integer("web-discovery:early-check:{$product->id}", 0))->toBe(1)
        ->and(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Proposed)
        ->and(findingAt($product, 'b.test')->status)->toBe(WebFindingStatus::Proposed);
});

it('lets an early check past the cap wait for the reads still to come', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p'], ['title' => 'Coffee beans', 'link' => 'https://b.test/p']], [
        'https://a.test/p' => htmlPage(discoveryPage('Coffee beans')),
    ]);
    Queue::fake();
    new DiscoverWebShops((string) $product->id)->handle(app(WebShopDiscovery::class));
    new ReadWebFinding(findingAt($product, 'a.test')->id)->handle(app(WebPageReads::class));
    Cache::put("web-discovery:early-check:{$product->id}", 4, 600);

    new CheckWebFindings((string) $product->id, early: true)->handle(app(WebSecondCheck::class));

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Read)
        ->and(findingAt($product, 'b.test')->status)->toBe(WebFindingStatus::PendingRead);
});

it('queues one second check per product, and only until it starts, so a later read queues the next', function (): void {
    Queue::fake();

    dispatch(new CheckWebFindings('a'));
    dispatch(new CheckWebFindings('a'));
    dispatch(new CheckWebFindings('b'));

    Queue::assertPushed(CheckWebFindings::class, 2);
    expect(class_implements(CheckWebFindings::class))->toHaveKey(ShouldBeUniqueUntilProcessing::class);
});

it('puts back only claims older than any live check', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p'], ['title' => 'Coffee beans', 'link' => 'https://b.test/p']], []);
    Queue::fake();
    discover($product);

    WebShopFinding::query()->where('product_id', $product->id)->where('host', 'a.test')->update(['status' => WebFindingStatus::Checking, 'claimed_at' => now()->subMinutes(5)]);
    WebShopFinding::query()->where('product_id', $product->id)->where('host', 'b.test')->update(['status' => WebFindingStatus::Checking, 'claimed_at' => now()]);

    new CheckWebFindings((string) $product->id)->failed();

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Read)
        ->and(findingAt($product, 'b.test')->status)->toBe(WebFindingStatus::Checking);
});

it('puts its own claims back when the second check throws', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);
    Queue::fake();
    discover($product);
    app(WebPageReads::class)->read(findingAt($product, 'a.test')->load('product.user', 'product.shops'));

    Http::swap(new Factory());
    Http::fake([TypeSafeClient::ENDPOINT => static fn () => throw new RuntimeException('boom')]);

    expect(fn () => app(WebSecondCheck::class)->check($product->refresh()->load('user', 'shops')))->toThrow(RuntimeException::class)
        ->and(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Read);
});

it('counts a page rejected after its read against the read cap', function (): void {
    config()->set('dipcatch.web_discovery.max_reads_per_product', 1);
    $product = discoveryProduct();
    $organic = [['title' => 'Coffee beans', 'link' => 'https://a.test/p'], ['title' => 'Coffee unanswered', 'link' => 'https://b.test/p']];
    $pages = ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans', body: '<p>€ 17,49 excl. BTW</p>'))];
    fakeDiscovery($organic, $pages, static fn (array $candidate): ?float => str_contains($candidate['title'], 'unanswered') ? null : 0.9);

    discover($product);
    expect(findingAt($product, 'a.test')->failure)->toBe('not_a_consumer_price');

    // b.test is answered on the next run, but the one read is spent.
    Http::swap(new Factory());
    fakeDiscovery($organic, $pages);
    discover($product);

    expect(findingAt($product, 'b.test')->status)->toBe(WebFindingStatus::Rejected)
        ->and(findingAt($product, 'b.test')->failure)->toBe('read_cap');
});

it('goes on with stored findings when no search can be made', function (): void {
    $product = discoveryProduct();
    $organic = [['title' => 'Coffee beans', 'link' => 'https://a.test/p']];
    $pages = ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))];
    fakeDiscovery($organic, $pages, jevDown: true);
    discover($product);

    // The stored search is stale and the day's searches are spent.
    $this->travel(91)->days();
    config()->set('dipcatch.web_discovery.daily_search_limit', 1);
    Cache::put('web-discovery:searches:' . now()->toDateString(), 1, now()->endOfDay());
    Http::swap(new Factory());
    fakeDiscovery($organic, $pages);

    discover($product);

    expect(serperCalls())->toBe(0)
        ->and(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Proposed);
});

it('keeps a hidden finding hidden through a search refresh', function (): void {
    $product = discoveryProduct();
    $organic = [['title' => 'Coffee beans', 'link' => 'https://a.test/p']];
    $pages = ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))];
    fakeDiscovery($organic, $pages);
    discover($product);
    WebShopFinding::query()->where('product_id', $product->id)->update(['dismissed_at' => now()]);

    $this->travel(91)->days();
    Http::swap(new Factory());
    fakeDiscovery($organic, $pages);
    discover($product);
    discover($product);

    $finding = findingAt($product, 'a.test');
    expect($finding->dismissed_at)->not->toBeNull()
        ->and($finding->generation)->toBe(0)
        ->and(WebShopFinding::shownFor($product->refresh())->all())->toBeEmpty();
});

it('sends the page pack size and barcode to the second check', function (): void {
    $product = discoveryProduct();
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://a.test/p']],
        ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans 4 x 1 kg', gtin: '8711000000090'))],
        static fn (array $candidate): float => ($candidate['pack_size'] ?? null) === '4000 g' ? 0.1 : 0.9,
    );

    discover($product);

    [, $second] = jevCandidates();
    expect(array_first($second))->toMatchArray(['pack_size' => '4000 g', 'gtin' => '8711000000090'])
        ->and(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Declined);
});

it('gives the read slots to the highest chances, whatever the search order', function (): void {
    config()->set('dipcatch.web_discovery.max_reads_per_product', 1);
    $product = discoveryProduct();
    fakeDiscovery(
        [['title' => 'Coffee lower', 'link' => 'https://a.test/p'], ['title' => 'Coffee higher', 'link' => 'https://b.test/p']],
        ['https://b.test/p' => htmlPage(discoveryPage('Coffee beans'))],
        static fn (array $candidate): float => str_contains($candidate['title'], 'lower') ? 0.8 : 0.9,
    );

    discover($product);

    expect(findingAt($product, 'b.test')->status)->toBe(WebFindingStatus::Proposed)
        ->and(findingAt($product, 'a.test')->failure)->toBe('read_cap');
});

it('reads from read_from and proposes from accept_from, both inclusive', function (float $first, float $second, WebFindingStatus $expected): void {
    $product = discoveryProduct();
    $calls = 0;
    fakeDiscovery(
        [['title' => 'Coffee beans', 'link' => 'https://a.test/p']],
        ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))],
        static function () use (&$calls, $first, $second): float {
            return ++$calls === 1 ? $first : $second;
        },
    );

    discover($product);

    expect(findingAt($product, 'a.test')->status)->toBe($expected);
})->with([
    'first check just below' => [0.29, 0.9, WebFindingStatus::Rejected],
    'first check at the bar' => [0.3, 0.9, WebFindingStatus::Proposed],
    'second check just below' => [0.9, 0.59, WebFindingStatus::Declined],
    'second check at the bar' => [0.9, 0.6, WebFindingStatus::Proposed],
]);

it('caps the delay a shop asks for', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => Http::response('slow down', 429, ['Retry-After' => '3600'])]);
    Queue::fake();
    discover($product);

    expect(app(WebPageReads::class)->read(findingAt($product, 'a.test')))->toBe(900);
});

it('leaves a finished read alone when a late failure handler runs', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => htmlPage(discoveryPage('Coffee beans'))]);
    discover($product);

    new ReadWebFinding(findingAt($product, 'a.test')->id)->failed();

    expect(findingAt($product, 'a.test')->status)->toBe(WebFindingStatus::Proposed)
        ->and(findingAt($product, 'a.test')->failure)->toBeNull();
});

it('releases the read job for the delay the shop asks', function (): void {
    $product = discoveryProduct();
    fakeDiscovery([['title' => 'Coffee beans', 'link' => 'https://a.test/p']], ['https://a.test/p' => Http::response('slow down', 429, ['Retry-After' => '120'])]);
    Queue::fake();
    discover($product);

    $job = new ReadWebFinding(findingAt($product, 'a.test')->id)->withFakeQueueInteractions();
    $job->handle(app(WebPageReads::class));

    $job->assertReleased(delay: 120);
});

it('starts stale findings over from the stored search when no new search can be made', function (): void {
    config()->set('dipcatch.web_discovery.daily_search_limit', 1);
    $product = discoveryProduct();
    fakeDiscovery(
        [['title' => 'Coffee beans 1kg — Koffiehenk', 'link' => 'https://koffiehenk.test/coffee-1kg', 'snippet' => 'Beans in a 1 kg bag']],
        ['https://koffiehenk.test/coffee-1kg' => htmlPage(discoveryPage('Coffee beans'))],
    );
    discover($product);

    // A shop in another pack size makes every finding stale, and the stored
    // search now counts as old, with the day's one search already spent.
    Shop::factory()->for($product)->create(['url' => 'https://other.test/p/1', 'host' => 'other.test', 'pack_quantity' => '500.00', 'pack_unit' => 'g']);
    config()->set('dipcatch.web_discovery.search_max_age_days', 0);
    $this->travel(1)->minutes();

    discover($product);

    $finding = findingAt($product, 'koffiehenk.test');

    expect($finding->fingerprint)->toBe(WebShopFinding::fingerprintFor($product->refresh()->load('shops')))
        ->and($finding->status)->toBe(WebFindingStatus::Proposed)
        ->and(serperCalls())->toBe(1);
});
