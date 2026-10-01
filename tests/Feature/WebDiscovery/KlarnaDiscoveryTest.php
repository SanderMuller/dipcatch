<?php declare(strict_types=1);

use App\Actions\Shops\ProbeShopUrl;
use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Jobs\DiscoverWebShops;
use App\Jobs\FindKlarnaPage;
use App\Jobs\LookUpKlarnaLead;
use App\Jobs\ReadKlarnaLeads;
use App\Livewire\Shops\AddShop;
use App\Livewire\Suggestions\ShopSuggestions;
use App\Models\HiddenShop;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebSearch;
use App\Models\WebShopFinding;
use App\Services\ShopDiscovery\KlarnaDiscovery;
use App\Services\ShopDiscovery\KlarnaSource;
use App\Services\ShopDiscovery\SerperProvider;
use App\Services\ShopDiscovery\WebShopDiscovery;
use App\Services\TypeSafe\TypeSafeClient;
use App\Support\UrlNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function klarnaSourceUrl(): string
{
    return 'https://www.klarna.com/nl/shopping/pl/cl456/3202266158/Huisdieren/Hill-s-10kg-Young-Adult-Sterilised-met-Eend-Science/';
}

function klarnaKittenUrl(): string
{
    return 'https://www.klarna.com/nl/shopping/pl/cl456/111/Huisdieren/Hill-s-Kitten/';
}

function medpetsLeadUrl(): string
{
    return 'https://www.medpets.nl/hills-science-plan-feline-young-adult-sterilised-duck';
}

function brekzLeadUrl(): string
{
    return 'https://www.brekz.nl/hills-kattenvoer/hill-s-adult-sterilised-cat-met-eend-kattenvoer.html';
}

beforeEach(function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    config()->set('dipcatch.web_discovery.klarna_leads', true);
    config()->set('dipcatch.shop_checks.accept_from', 0.6);
    Cache::flush();
    Http::preventStrayRequests();
});

/** A Pro product tracking the 10 kg bag at zooplus.nl. */
function klarnaProduct(): Product
{
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['title' => 'Hill’s Young Adult Sterilised Eend 10 kg', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://www.zooplus.nl/shop/hills/939943', 'pack_quantity' => '10000.00', 'pack_unit' => 'g']);

    return $product->refresh()->load('user', 'shops');
}

/**
 * @return array{title: string, link: string, snippet: string}
 */
function klarnaResult(string $title, string $link): array
{
    return ['title' => $title, 'link' => $link, 'snippet' => ''];
}

/** A one-product page with its JSON-LD price and size in the title. */
function leadPage(string $title, string $price): string
{
    return withJsonLd(json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $title,
        'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
    ], JSON_THROW_ON_ERROR));
}

/**
 * Fakes Serper per query, Jev, the Klarna page and the shop pages. The first
 * `$searches` key a query contains decides its results. Jev answers 0.9, or
 * 0.1 for a title with "Kitten" or "category". Every question Jev got is kept
 * in `$questions`.
 *
 * @param  array<string, list<array{title: string, link: string, snippet: string}>|int|Closure(): mixed>  $searches  An int answers with that HTTP status; a closure answers itself.
 * @param  array<string, string>  $pages
 * @param  list<array{key: string, title: string, question: string}>  $questions
 * @param  int  $klarnaStatus  The HTTP status the Klarna page answers with.
 * @param  (Closure(Request): mixed)|null  $typeSafe  Answers Jev in place of the default scores.
 */
function fakeKlarnaWorld(array $searches, array $pages = [], ?string $klarnaHtml = null, array &$questions = [], int $klarnaStatus = 200, ?Closure $typeSafe = null): void
{
    $robots = [];

    foreach ([klarnaSourceUrl(), ...array_keys($pages)] as $url) {
        $robots[parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/robots.txt'] = Http::response('', 404);
    }

    $pageResponses = array_map(static fn (string $html): Closure => static fn () => Http::response($html, 200, ['Content-Type' => 'text/html']), $pages);

    Http::fake([
        SerperProvider::ENDPOINT => static function (Request $request) use ($searches) {
            $query = serperQuery($request);

            foreach ($searches as $needle => $organic) {
                if (str_contains($query, $needle)) {
                    return match (true) {
                        $organic instanceof Closure => $organic(),
                        is_int($organic) => Http::response([], $organic),
                        default => Http::response(['organic' => $organic]),
                    };
                }
            }

            return Http::response(['organic' => []]);
        },
        TypeSafeClient::ENDPOINT => $typeSafe ?? static function (Request $request) use (&$questions) {
            $answers = [];

            foreach (requestedQuestions($request) as $key => [$title, $text]) {
                $questions[] = ['key' => $key, 'title' => $title, 'question' => $text];
                $answers[$key] = ['noul' => str_contains($title, 'Kitten') || str_contains($title, 'category') ? 0.1 : 0.9];
            }

            return Http::response(['answers' => $answers]);
        },
        klarnaSourceUrl() => Http::response($klarnaStatus === 200 ? $klarnaHtml ?? File::get(base_path('tests/Fixtures/klarna/hills_sterilised_10kg.html')) : '', $klarnaStatus, ['Content-Type' => 'text/html']),
        ...$robots,
        ...$pageResponses,
    ]);
}

function serperQuery(Request $request): string
{
    $query = $request->data()['q'] ?? null;

    return is_string($query) ? $query : '';
}

/**
 * Each same-product question in a TypeSafe request: the candidate's title and
 * the question text, keyed as the request keyed them.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function requestedQuestions(Request $request): array
{
    $questions = $request->data()['questions'] ?? null;
    $out = [];

    foreach (is_array($questions) ? $questions : [] as $key => $question) {
        $instructions = is_array($question) && is_array($question['instructions'] ?? null) ? $question['instructions'] : [];
        $candidate = is_array($instructions['candidate'] ?? null) ? $instructions['candidate'] : [];
        $out[(string) $key] = [
            is_string($candidate['title'] ?? null) ? $candidate['title'] : '',
            is_string($instructions['question'] ?? null) ? $instructions['question'] : '',
        ];
    }

    return $out;
}

function medpetsPage(): string
{
    return File::get(base_path('tests/Fixtures/variant-names/medpets_variants.html'));
}

/**
 * The searches of the happy path: Klarna found by search, two leads looked up.
 *
 * @return array<string, list<array{title: string, link: string, snippet: string}>>
 */
function happyKlarnaSearches(): array
{
    return [
        'site:klarna.com' => [klarnaResult('Hill’s Kitten Kip • Klarna', klarnaKittenUrl()), klarnaResult('Hill’s 10kg Young Adult Sterilised met Eend', klarnaSourceUrl())],
        'site:medpets.nl' => [klarnaResult('Hill’s kattenvoer category', 'https://www.medpets.nl/hills/katten/voer'), klarnaResult('Hill’s Science Plan Sterilised Cat - Adult - Eend', medpetsLeadUrl())],
        'site:brekz.nl' => [klarnaResult('Hill’s Adult Sterilised Cat met eend kattenvoer', brekzLeadUrl())],
        'Hill’s Young Adult' => [],
    ];
}

function discoverKlarna(Product $product): void
{
    app(WebShopDiscovery::class)->discover($product);
}

test('a Klarna page found by search brings in its shops as checked web suggestions', function (): void {
    $questions = [];
    fakeKlarnaWorld(happyKlarnaSearches(), [
        medpetsLeadUrl() => medpetsPage(),
        brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69'),
    ], questions: $questions);
    $product = klarnaProduct();

    discoverKlarna($product);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_url)->toBe(klarnaSourceUrl())
        ->and($discovery->klarna_checked_at)->not->toBeNull()
        ->and($discovery->state)->toBe(WebDiscoveryState::Done)
        ->and(array_column($discovery->klarna_leads ?? [], 'state', 'host'))->toBe(['medpets.nl' => 'done', 'brekz.nl' => 'done']);

    $medpets = WebShopFinding::query()->where('product_id', $product->id)->where('url', medpetsLeadUrl())->sole();
    expect($medpets->status)->toBe(WebFindingStatus::Proposed)
        ->and($medpets->lead_url)->toBe(klarnaSourceUrl())
        ->and($medpets->variant_key)->toBe(medpetsLeadUrl() . '?sku=MP28084')
        ->and($medpets->page_price)->toBe('76.05')
        ->and((float) $medpets->page_pack_quantity)->toBe(10000.0)
        ->and(WebShopFinding::query()->where('url', 'https://www.medpets.nl/hills/katten/voer')->sole()->status)->toBe(WebFindingStatus::Rejected)
        ->and(WebShopFinding::query()->where('url', brekzLeadUrl())->sole()->status)->toBe(WebFindingStatus::Proposed)
        ->and(WebShopFinding::query()->where('host', 'zooplus.nl')->exists())->toBeFalse();

    // Klarna's results and every lead finding are asked about the product in any size.
    $leadQuestions = array_filter($questions, static fn (array $question): bool => str_contains($question['title'], 'Sterilised') || str_contains($question['title'], 'Kitten'));
    expect($leadQuestions)->not->toBeEmpty()
        ->and(array_unique(array_column($leadQuestions, 'question')))->toBe([array_values($leadQuestions)[0]['question']])
        ->and(array_values($leadQuestions)[0]['question'])->toContain('in any pack size');
});

test('a Klarna page the product already tracks is the source without a search', function (): void {
    fakeKlarnaWorld(['Hill’s Young Adult' => []]);
    $product = klarnaProduct();
    Shop::factory()->for($product)->create(['url' => klarnaSourceUrl(), 'kind' => 'reference', 'unreadable_reason' => 'not_a_shop', 'current_price' => null]);
    WebDiscovery::markQueued($product);
    Queue::fake([ReadKlarnaLeads::class]);

    app(KlarnaDiscovery::class)->findPage($product->refresh()->load('shops', 'user'), 0);

    expect(WebDiscovery::query()->find($product->id)?->klarna_url)->toBe(klarnaSourceUrl());
    Http::assertNotSent(static fn (Request $request): bool => str_contains(serperQuery($request), 'site:klarna.com'));
});

test('queued Klarna steps do nothing once the Klarna steps are switched off', function (): void {
    fakeKlarnaWorld(['Hill’s Young Adult' => []]);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update([
        'klarna_url' => klarnaSourceUrl(),
        'klarna_leads' => json_encode([['host' => 'medpets.nl', 'title' => 'Hill’s Sterilised Cat Eend 10 kg', 'pack_quantity' => 10000, 'pack_unit' => 'g', 'state' => 'pending', 'attempts' => 0]]),
    ]);
    config()->set('dipcatch.web_discovery.klarna_leads', false);

    app(KlarnaDiscovery::class)->lookUp($product, 0, 'medpets.nl');
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_leads' => null]);
    app(KlarnaDiscovery::class)->readLeads($product, 0);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => null]);
    app(KlarnaDiscovery::class)->findPage($product->load('shops', 'user'), 0);

    Http::assertNothingSent();
    expect(WebDiscovery::query()->findOrFail($product->id)->klarna_url)->toBeNull();
});

test('a product that is not on Klarna ends the Klarna work without a source', function (): void {
    fakeKlarnaWorld(['site:klarna.com' => [], 'Hill’s Young Adult' => []]);
    $product = klarnaProduct();

    discoverKlarna($product);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_url)->toBeNull()
        ->and($discovery->klarna_checked_at)->not->toBeNull()
        ->and($discovery->state)->toBe(WebDiscoveryState::Done);
});

test('a Klarna search that fails counts an attempt and leaves the work unfinished', function (): void {
    fakeKlarnaWorld(['site:klarna.com' => 500, 'Hill’s Young Adult' => []]);
    $product = klarnaProduct();

    discoverKlarna($product);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_attempts)->toBe(1)
        ->and($discovery->klarna_checked_at)->toBeNull()
        ->and($discovery->state)->toBe(WebDiscoveryState::Running);
});

test('a spent search limit or AI budget defers the Klarna search without an attempt', function (string $spent): void {
    fakeKlarnaWorld(['site:klarna.com' => [klarnaResult('Hill’s 10kg Young Adult Sterilised met Eend', klarnaSourceUrl())], 'Hill’s Young Adult' => []]);
    $product = klarnaProduct();
    discoverKlarna($product);
    // The discovery above already resolved; start a new generation to test the deferral.
    $generation = KlarnaSource::restart($product);

    if ($spent === 'search') {
        config()->set('dipcatch.web_discovery.daily_search_limit', 1);
        Cache::put('web-discovery:searches:' . now()->toDateString(), 1, now()->endOfDay());
        Cache::forget('web-discovery:search');
        WebSearch::query()->delete();
    } else {
        // The discovery above spent today's one check.
        config()->set('dipcatch.shop_checks.daily_limit_per_user', 1);
    }

    app(KlarnaDiscovery::class)->findPage($product, $generation);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_attempts)->toBe(0)
        ->and($discovery->klarna_url)->toBeNull()
        ->and($discovery->klarna_checked_at)->toBeNull();
})->with(['search', 'ai']);

/**
 * The hosts `readLeads()` keeps after `$skip` set up one reason to skip.
 *
 * @param  Closure(Product, string): string  $skip
 * @return list<string>
 */
function readLeadsAfter(Closure $skip): array
{
    $html = File::get(base_path('tests/Fixtures/klarna/hills_sterilised_10kg.html'));
    Queue::fake([LookUpKlarnaLead::class]);
    $product = klarnaProduct();
    fakeKlarnaWorld([], klarnaHtml: $skip($product, $html));
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl()]);

    app(KlarnaDiscovery::class)->readLeads($product, 0);

    return array_column(WebDiscovery::query()->findOrFail($product->id)->klarna_leads ?? [], 'host');
}

test('leads skip a shop the product tracks, hides, already checks, or that is a comparison site or unsupported', function (Closure $skip, array $kept): void {
    // zooplus.nl is tracked in every case.
    expect(readLeadsAfter($skip))->toBe($kept);
})->with([
    'hidden' => [function (Product $product, string $html): string {
        HiddenShop::hide($product->user()->sole(), 'medpets.nl');

        return $html;
    }, ['brekz.nl']],
    'comparison site' => [fn (Product $product, string $html): string => str_replace('merchantUrl=brekz.nl', 'merchantUrl=pricerunner.com', $html), ['medpets.nl']],
    'unsupported' => [function (Product $product, string $html): string {
        config()->set('site.unsupported_hosts', ['brekz.nl']);

        return $html;
    }, ['medpets.nl']],
    'already checked' => [function (Product $product, string $html): string {
        WebShopFinding::query()->forceCreate(['product_id' => $product->id, 'url' => 'https://www.medpets.nl/p', 'url_hash' => 'busy', 'host' => 'medpets.nl', 'search_title' => 'x', 'status' => WebFindingStatus::Proposed, 'fingerprint' => WebShopFinding::fingerprintFor($product)]);

        return $html;
    }, ['brekz.nl']],
    'turned away before' => [function (Product $product, string $html): string {
        WebShopFinding::query()->forceCreate(['product_id' => $product->id, 'url' => 'https://www.medpets.nl/p', 'url_hash' => 'unreadable', 'host' => 'medpets.nl', 'search_title' => 'x', 'status' => WebFindingStatus::Unreadable, 'failure' => 'ambiguous', 'fingerprint' => WebShopFinding::fingerprintFor($product)]);

        return $html;
    }, ['medpets.nl', 'brekz.nl']],
]);

test('one lead per shop: the tracked size wins over a multipack, and a host that does not resolve is skipped', function (): void {
    config()->set('dipcatch.fetcher.allow_private_ips', false);
    config()->set('dipcatch.fetcher.allow_unresolved', false);
    Cache::put('dipcatch:dns:www.klarna.com', ['93.184.216.34'], 300);
    Cache::put('dipcatch:dns:brekz.nl', ['93.184.216.34'], 300);
    Cache::put('dipcatch:dns:joybuy.dnu', [], 300);
    $html = str_replace('merchantUrl=medpets.nl', 'merchantUrl=joybuy.dnu', File::get(base_path('tests/Fixtures/klarna/hills_sterilised_10kg.html')));
    fakeKlarnaWorld([], klarnaHtml: $html);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl()]);
    Queue::fake([LookUpKlarnaLead::class]);

    app(KlarnaDiscovery::class)->readLeads($product, 0);

    $leads = WebDiscovery::query()->findOrFail($product->id)->klarna_leads;
    expect($leads)->toHaveCount(1)
        ->and(array_column($leads ?? [], 'host'))->toBe(['brekz.nl'])
        ->and(array_column($leads ?? [], 'pack_quantity'))->toEqual([10000])
        ->and(array_column($leads ?? [], 'state'))->toBe(['pending']);
    Queue::assertPushed(LookUpKlarnaLead::class, 1);
});

test('a lookup with no page on the shop stores nothing and finishes its lead', function (): void {
    fakeKlarnaWorld(['site:medpets.nl' => [klarnaResult('Other site', 'https://elsewhere.nl/p')], 'site:brekz.nl' => []]);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl(), 'klarna_leads' => json_encode([
        ['host' => 'medpets.nl', 'title' => 'Hill’s 10 kg', 'pack_quantity' => 10000.0, 'pack_unit' => 'g', 'state' => 'pending', 'attempts' => 0],
    ])]);

    app(KlarnaDiscovery::class)->lookUp($product, 0, 'medpets.nl');

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect(WebShopFinding::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and(array_column($discovery->klarna_leads ?? [], 'state'))->toBe(['done'])
        ->and($discovery->klarna_checked_at)->not->toBeNull();
});

test('a spent search limit leaves leads pending for the next run, which finishes them', function (): void {
    fakeKlarnaWorld(happyKlarnaSearches(), [
        medpetsLeadUrl() => medpetsPage(),
        brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69'),
    ]);
    config()->set('dipcatch.web_discovery.daily_search_limit', 2);
    $product = klarnaProduct();

    // Open search and Klarna search spend the day's two searches.
    discoverKlarna($product);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect(array_column($discovery->klarna_leads ?? [], 'state'))->toBe(['pending', 'pending'])
        ->and(array_column($discovery->klarna_leads ?? [], 'attempts'))->toBe([0, 0])
        ->and($discovery->klarna_checked_at)->toBeNull();

    // The next day.
    $this->travel(1)->day();
    discoverKlarna($product->refresh()->load('user', 'shops'));

    $discovery->refresh();
    expect(array_column($discovery->klarna_leads ?? [], 'state'))->toBe(['done', 'done'])
        ->and($discovery->klarna_checked_at)->not->toBeNull()
        ->and(WebShopFinding::query()->where('product_id', $product->id)->where('status', WebFindingStatus::Proposed)->count())->toBe(2);
});

test('a lookup whose search fails counts against its lead until it gives up', function (): void {
    fakeKlarnaWorld(['site:medpets.nl' => 500]);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl(), 'klarna_leads' => json_encode([
        ['host' => 'medpets.nl', 'title' => 'Hill’s 10 kg', 'pack_quantity' => 10000.0, 'pack_unit' => 'g', 'state' => 'pending', 'attempts' => 0],
    ])]);

    foreach (range(1, 3) as $attempt) {
        app(KlarnaDiscovery::class)->lookUp($product, 0, 'medpets.nl');
    }

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect(array_column($discovery->klarna_leads ?? [], 'state'))->toBe(['failed'])
        ->and(array_column($discovery->klarna_leads ?? [], 'attempts'))->toBe([3])
        ->and($discovery->klarna_checked_at)->not->toBeNull();
});

test('a page an earlier check turned away comes back through a lead; a hidden one stays hidden', function (): void {
    fakeKlarnaWorld(['site:medpets.nl' => [klarnaResult('Hill’s Science Plan Sterilised Cat - Adult - Eend', medpetsLeadUrl()), klarnaResult('Hill’s other', 'https://www.medpets.nl/other')]], [medpetsLeadUrl() => medpetsPage()]);
    Queue::fake([DiscoverWebShops::class]);
    $product = klarnaProduct();
    $fingerprint = WebShopFinding::fingerprintFor($product);
    $unreadable = WebShopFinding::query()->forceCreate(['product_id' => $product->id, 'url' => medpetsLeadUrl(), 'url_hash' => UrlNormalizer::hash(UrlNormalizer::normalize(medpetsLeadUrl())), 'host' => 'medpets.nl', 'search_title' => 'Hill’s', 'status' => WebFindingStatus::Unreadable, 'failure' => 'ambiguous', 'fingerprint' => $fingerprint, 'generation' => 1, 'first_chance' => 0.9]);
    $hidden = WebShopFinding::query()->forceCreate(['product_id' => $product->id, 'url' => 'https://www.medpets.nl/other', 'url_hash' => UrlNormalizer::hash(UrlNormalizer::normalize('https://www.medpets.nl/other')), 'host' => 'medpets.nl', 'search_title' => 'Hill’s', 'status' => WebFindingStatus::Declined, 'fingerprint' => $fingerprint, 'dismissed_at' => now()]);
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl(), 'klarna_leads' => json_encode([
        ['host' => 'medpets.nl', 'title' => 'Hill’s Science Plan Sterilised Cat - Adult - Eend - 10 kg', 'pack_quantity' => 10000.0, 'pack_unit' => 'g', 'state' => 'pending', 'attempts' => 0],
    ])]);

    app(KlarnaDiscovery::class)->lookUp($product, 0, 'medpets.nl');

    expect($unreadable->refresh()->status)->toBe(WebFindingStatus::New)
        ->and($unreadable->lead_url)->toBe(klarnaSourceUrl())
        ->and($unreadable->generation)->toBe(2)
        ->and($unreadable->failure)->toBeNull()
        ->and($hidden->refresh()->status)->toBe(WebFindingStatus::Declined)
        ->and($hidden->lead_url)->toBeNull();
    Queue::assertPushed(DiscoverWebShops::class);
});

test('a lead in another size reads that size and is shown as another size', function (): void {
    $html = str_replace(['Eend - 10 kg"', '"76.05"'], ['Eend - 7 kg"', '"58.05"'], File::get(base_path('tests/Fixtures/klarna/hills_sterilised_10kg.html')));
    fakeKlarnaWorld(happyKlarnaSearches(), [
        medpetsLeadUrl() => medpetsPage(),
        brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69'),
    ], klarnaHtml: $html);
    $product = klarnaProduct();

    discoverKlarna($product);

    $medpets = WebShopFinding::query()->where('url', medpetsLeadUrl())->sole();
    expect($medpets->variant_key)->toBe(medpetsLeadUrl() . '?sku=MP29294')
        ->and((float) $medpets->page_pack_quantity)->toBe(7000.0)
        ->and($medpets->otherSizeNote($product))->toBe('Other size: 7 kg — compared per kilo')
        ->and(WebShopFinding::query()->where('url', brekzLeadUrl())->sole()->otherSizeNote($product))->toBeNull();

    $this->actingAs($product->user()->sole());
    Livewire\Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSee('Other size: 7 kg — compared per kilo')
        ->assertSee('Found through Klarna');
});

test('a refreshed open search keeps lead findings, and a new Klarna page drops the old page’s ones', function (): void {
    fakeKlarnaWorld(happyKlarnaSearches(), [
        medpetsLeadUrl() => medpetsPage(),
        brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69'),
    ]);
    $product = klarnaProduct();
    discoverKlarna($product);
    $leads = WebShopFinding::query()->whereNotNull('lead_url')->count();
    $brekz = WebShopFinding::query()->where('url', brekzLeadUrl())->sole();
    expect($brekz->status)->toBe(WebFindingStatus::Proposed);

    // Another product refreshes the shared open search.
    WebSearch::query()->where('query', 'like', 'hill%young adult%')->update(['searched_at' => now()->addMinute()]);
    Queue::fake();
    discoverKlarna($product->refresh()->load('user', 'shops'));

    $kept = $brekz->fresh();
    expect(WebShopFinding::query()->whereNotNull('lead_url')->count())->toBe($leads)
        ->and($kept?->status)->toBe(WebFindingStatus::Proposed)
        ->and($kept?->generation)->toBe($brekz->generation)
        ->and(WebDiscovery::query()->findOrFail($product->id)->klarna_generation)->toBe(2);

    KlarnaSource::restart($product, klarnaKittenUrl());

    expect(WebShopFinding::query()->whereNotNull('lead_url')->count())->toBe(0);
});

test('a job of a replaced generation writes nothing', function (): void {
    fakeKlarnaWorld(['site:klarna.com' => [klarnaResult('Hill’s 10kg Young Adult Sterilised met Eend', klarnaSourceUrl())]]);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    KlarnaSource::restart($product);

    app(KlarnaDiscovery::class)->findPage($product, 0);
    app(KlarnaDiscovery::class)->countAttempt($product, 0);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_url)->toBeNull()
        ->and($discovery->klarna_attempts)->toBe(0);
});

test('the nightly run picks up a product whose Klarna work is unfinished', function (): void {
    Queue::fake();
    $product = klarnaProduct();
    $search = WebSearch::query()->forceCreate(['query_hash' => WebSearch::hashOf($product->title), 'query' => $product->title, 'results' => [], 'searched_at' => now()]);
    WebDiscovery::query()->forceCreate(['product_id' => $product->id, 'web_search_id' => $search->id, 'search_searched_at' => $search->searched_at, 'state' => WebDiscoveryState::Done->value]);

    $this->artisan('dipcatch:discover-web-shops')->assertSuccessful();

    Queue::assertPushed(DiscoverWebShops::class, 1);
});

test('the Klarna steps stay off when switched off', function (): void {
    config()->set('dipcatch.web_discovery.klarna_leads', false);
    fakeKlarnaWorld(['Hill’s Young Adult' => []]);
    $product = klarnaProduct();

    discoverKlarna($product);

    expect(WebDiscovery::query()->findOrFail($product->id)->state)->toBe(WebDiscoveryState::Done);
    Http::assertNotSent(static fn (Request $request): bool => str_contains(serperQuery($request), 'site:klarna.com'));
});

test('a Klarna page listed before ReadKlarnaLeads reads its leads only once', function (): void {
    Queue::fake([LookUpKlarnaLead::class]);
    fakeKlarnaWorld([]);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl()]);

    new ReadKlarnaLeads((string) $product->id, 0)->handle(app(KlarnaDiscovery::class));
    new ReadKlarnaLeads((string) $product->id, 0)->handle(app(KlarnaDiscovery::class));

    Queue::assertPushed(LookUpKlarnaLead::class, 2);
});

test('adding a Klarna lead suggestion tracks its variant and asks the any-size question', function (): void {
    $questions = [];
    $agradi = 'https://www.agradi.nl/products/cavalor-muscle-motion';
    fakeKlarnaWorld([], [$agradi => File::get(base_path('tests/Fixtures/variant-names/agradi_shopify_variants.html'))], questions: $questions);
    $product = klarnaProduct();
    $product->shops()->update(['pack_quantity' => '1000.00']);
    $finding = WebShopFinding::query()->forceCreate([
        'product_id' => $product->id, 'url' => $agradi, 'url_hash' => UrlNormalizer::hash($agradi), 'host' => 'agradi.nl',
        'search_title' => 'Cavalor Muscle Motion', 'status' => WebFindingStatus::Proposed, 'fingerprint' => WebShopFinding::fingerprintFor($product),
        'lead_url' => klarnaSourceUrl(), 'variant_key' => '44768357', 'add_url' => $agradi,
    ]);
    $this->actingAs($product->user()->sole());

    Livewire\Livewire::test(AddShop::class, ['product' => $product->refresh()])
        ->call('useSuggestion', $agradi, app(ProbeShopUrl::class), $finding->id)
        ->assertSet('chosenVariantKey', '44768357')
        ->assertSet('snapshot.price', '186.47');

    expect(array_column($questions, 'question'))->toHaveCount(1)
        ->and($questions[0]['question'])->toContain('in any pack size');
});

test('a pasted link that is not the suggestion asks the same-size question', function (): void {
    $questions = [];
    fakeKlarnaWorld([], [brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69')], questions: $questions);
    $product = klarnaProduct();
    $this->actingAs($product->user()->sole());

    Livewire\Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', brekzLeadUrl())
        ->call('probe');

    expect($questions[0]['question'] ?? '')->not->toContain('in any pack size');
});

test('leads in another currency, or in a unit the product does not compare in, are skipped', function (): void {
    $html = str_replace(
        ['Hill&apos;s Adult Sterilised Cat met eend kattenvoer 10 kg"', '"amount": "76.05", "currency": "EUR"'],
        ['Hill&apos;s Adult Sterilised Cat met eend 12 stuks"', '"amount": "76.05", "currency": "USD"'],
        File::get(base_path('tests/Fixtures/klarna/hills_sterilised_10kg.html')),
    );
    fakeKlarnaWorld([], klarnaHtml: $html);
    Queue::fake([LookUpKlarnaLead::class]);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl()]);

    app(KlarnaDiscovery::class)->readLeads($product, 0);

    // Medpets in USD, Brekz' 10 kg offer now counted in pieces: only Brekz' 2 x 10 kg is left.
    $leads = WebDiscovery::query()->findOrFail($product->id)->klarna_leads;
    expect(array_column($leads ?? [], 'host'))->toBe(['brekz.nl'])
        ->and(array_column($leads ?? [], 'pack_quantity'))->toEqual([20000]);
});

test('a lookup reads the best-scoring page per shop; the others are another page on that shop', function (): void {
    // The first result scores 0.1, which still passes; the second scores 0.9.
    config()->set('dipcatch.web_discovery.read_from', 0.1);
    $other = 'https://www.medpets.nl/hills-sterilised-eend-andere-pagina';
    $searches = happyKlarnaSearches();
    $searches['site:medpets.nl'] = [klarnaResult('Hill’s Sterilised Cat Eend category', $other), klarnaResult('Hill’s Science Plan Sterilised Cat - Adult - Eend', medpetsLeadUrl())];
    fakeKlarnaWorld($searches, [
        medpetsLeadUrl() => medpetsPage(),
        brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69'),
    ]);
    $product = klarnaProduct();

    discoverKlarna($product);

    $medpets = WebShopFinding::query()->where('host', 'medpets.nl')->get()->keyBy('url');
    expect($medpets->get(medpetsLeadUrl())?->status)->toBe(WebFindingStatus::Proposed)
        ->and($medpets->get($other)?->status)->toBe(WebFindingStatus::Rejected)
        ->and($medpets->get($other)?->failure)->toBe('other_page_on_host');
});

test('lead findings have their own read cap, apart from the open search', function (): void {
    config()->set('dipcatch.web_discovery.max_reads_per_product', 0);
    $searches = happyKlarnaSearches();
    $searches['Hill’s Young Adult'] = [klarnaResult('Hill’s Young Adult Sterilised Eend 10 kg', 'https://www.dierenwinkel.test/hills')];
    fakeKlarnaWorld($searches, [
        medpetsLeadUrl() => medpetsPage(),
        brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69'),
    ]);
    $product = klarnaProduct();

    discoverKlarna($product);

    expect(WebShopFinding::query()->where('host', 'dierenwinkel.test')->sole()->failure)->toBe('read_cap')
        ->and(WebShopFinding::query()->whereNotNull('lead_url')->where('status', WebFindingStatus::Proposed)->count())->toBe(2);
});

test('a lead finding found on a shop subdomain counts as that shop', function (): void {
    fakeKlarnaWorld(['site:medpets.nl' => [klarnaResult('Hill’s', 'https://shop.medpets.nl/hills')]]);
    Queue::fake([DiscoverWebShops::class]);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl(), 'klarna_leads' => json_encode([
        ['host' => 'medpets.nl', 'title' => 'Hill’s 10 kg', 'pack_quantity' => 10000.0, 'pack_unit' => 'g', 'state' => 'pending', 'attempts' => 0],
    ])]);

    app(KlarnaDiscovery::class)->lookUp($product, 0, 'medpets.nl');

    expect(WebShopFinding::query()->sole()->host)->toBe('medpets.nl');
});

test('a lead finding checked against an old pack size starts over and keeps its lead', function (): void {
    fakeKlarnaWorld(happyKlarnaSearches(), [
        medpetsLeadUrl() => medpetsPage(),
        brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69'),
    ]);
    $product = klarnaProduct();
    discoverKlarna($product);
    $finding = WebShopFinding::query()->where('url', brekzLeadUrl())->sole();
    $generation = $finding->generation;

    Queue::fake();
    Shop::factory()->for($product)->create(['url' => 'https://www.welkoop.nl/hills', 'pack_quantity' => '1500.00', 'pack_unit' => 'g']);
    discoverKlarna($product->refresh()->load('user', 'shops'));

    $finding->refresh();
    // Back through the first check, its earlier read forgotten.
    expect($finding->status)->toBe(WebFindingStatus::PendingRead)
        ->and($finding->lead_url)->toBe(klarnaSourceUrl())
        ->and($finding->generation)->toBe($generation + 1)
        ->and($finding->page_price)->toBeNull();
});

test('a Klarna step that gives up keeps the suggestions it made; a product found not to be on Klarna drops them', function (): void {
    $klarna = new stdClass();
    $klarna->search = 'happy';
    $searches = happyKlarnaSearches();
    // Decided per request, so the test can change Klarna's answer.
    $searches['site:klarna.com'] = static function () use ($klarna) {
        return match ($klarna->search) {
            'happy' => Http::response(['organic' => happyKlarnaSearches()['site:klarna.com']]),
            'down' => Http::response([], 500),
            default => Http::response(['organic' => []]),
        };
    };
    fakeKlarnaWorld($searches, [
        medpetsLeadUrl() => medpetsPage(),
        brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69'),
    ]);
    $product = klarnaProduct();
    discoverKlarna($product);
    expect(WebShopFinding::query()->whereNotNull('lead_url')->where('status', WebFindingStatus::Proposed)->count())->toBe(2);

    WebSearch::query()->where('query', 'like', 'site:klarna.com%')->delete();
    $generation = KlarnaSource::restart($product);
    $klarna->search = 'down';

    foreach (range(1, 3) as $attempt) {
        app(KlarnaDiscovery::class)->findPage($product, $generation);
    }

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_url)->toBeNull()
        ->and($discovery->klarna_checked_at)->not->toBeNull()
        ->and(WebShopFinding::query()->whereNotNull('lead_url')->where('status', WebFindingStatus::Proposed)->count())->toBe(2);

    $generation = KlarnaSource::restart($product);
    $klarna->search = 'empty';
    app(KlarnaDiscovery::class)->findPage($product, $generation);

    expect(WebDiscovery::query()->findOrFail($product->id)->klarna_url)->toBeNull()
        ->and(WebShopFinding::query()->whereNotNull('lead_url')->count())->toBe(0);
});

test('a generation whose leads all settled without finishing is finished by the next run', function (): void {
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl(), 'klarna_leads' => json_encode([
        ['host' => 'medpets.nl', 'title' => 'Hill’s', 'pack_quantity' => null, 'pack_unit' => null, 'state' => 'done', 'attempts' => 0],
    ])]);

    app(KlarnaDiscovery::class)->continue($product);

    expect(WebDiscovery::query()->findOrFail($product->id)->klarna_checked_at)->not->toBeNull();
});

test('a lookup whose source was replaced while it searched writes no finding', function (): void {
    $product = klarnaProduct();
    Http::fake([
        SerperProvider::ENDPOINT => function () use ($product) {
            // A user pastes another Klarna page meanwhile.
            KlarnaSource::restart($product, klarnaKittenUrl());

            return Http::response(['organic' => [klarnaResult('Hill’s', medpetsLeadUrl())]]);
        },
    ]);
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl(), 'klarna_leads' => json_encode([
        ['host' => 'medpets.nl', 'title' => 'Hill’s 10 kg', 'pack_quantity' => 10000.0, 'pack_unit' => 'g', 'state' => 'pending', 'attempts' => 0],
    ])]);

    app(KlarnaDiscovery::class)->lookUp($product, 0, 'medpets.nl');

    expect(WebShopFinding::query()->count())->toBe(0);
});

test('a pasted Klarna page stays the source when the open search is built while it is looked up', function (): void {
    Queue::fake([ReadKlarnaLeads::class]);
    fakeKlarnaWorld(['Hill’s Young Adult' => []]);
    $product = klarnaProduct();

    expect(app(WebShopDiscovery::class)->useKlarnaPage($product, klarnaKittenUrl()))->toBeTrue();
    $generation = WebDiscovery::query()->findOrFail($product->id)->klarna_generation;

    discoverKlarna($product);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_url)->toBe(klarnaKittenUrl())
        ->and($discovery->klarna_generation)->toBe($generation);
});

test('the nightly run counts Klarna work against the day’s searches', function (): void {
    Queue::fake();
    config()->set('dipcatch.web_discovery.daily_search_limit', 3);
    $product = klarnaProduct();
    $search = WebSearch::query()->forceCreate(['query_hash' => WebSearch::hashOf($product->title), 'query' => $product->title, 'results' => [], 'searched_at' => now()]);
    WebDiscovery::query()->forceCreate(['product_id' => $product->id, 'web_search_id' => $search->id, 'search_searched_at' => $search->searched_at, 'state' => WebDiscoveryState::Done->value]);

    $this->artisan('dipcatch:discover-web-shops')->assertSuccessful();

    // One Klarna search plus three lookups do not fit in three searches.
    Queue::assertNotPushed(DiscoverWebShops::class);
});

/**
 * A lead finding from the Klarna page, at `$host`, in `$status`.
 *
 * @param  array<string, mixed>  $state
 */
function storedLeadFinding(Product $product, string $url, string $host, WebFindingStatus $status, array $state = []): WebShopFinding
{
    return WebShopFinding::query()->forceCreate(array_replace([
        'product_id' => $product->id,
        'url' => $url,
        'url_hash' => UrlNormalizer::hash(UrlNormalizer::normalize($url)),
        'host' => $host,
        'search_title' => "Hill’s Sterilised Eend at {$host}",
        'status' => $status,
        'fingerprint' => WebShopFinding::fingerprintFor($product),
        'lead_url' => klarnaSourceUrl(),
    ], $state));
}

test('one first check asks lead findings about any pack size and open findings about the tracked size', function (): void {
    // Keeps the Klarna steps out, so only the findings below are asked about.
    config()->set('dipcatch.web_discovery.klarna_leads', false);
    $questions = [];
    fakeKlarnaWorld(['Hill’s Young Adult' => [klarnaResult('Hill’s Young Adult Sterilised Eend 10 kg', 'https://www.dierenwinkel.test/hills')]], questions: $questions);
    Queue::fake();
    $product = klarnaProduct();
    storedLeadFinding($product, medpetsLeadUrl(), 'medpets.nl', WebFindingStatus::New);
    storedLeadFinding($product, brekzLeadUrl(), 'brekz.nl', WebFindingStatus::New);

    discoverKlarna($product);

    $asked = array_column($questions, 'question', 'title');
    expect($asked)->toHaveCount(3)
        ->and($asked['Hill’s Young Adult Sterilised Eend 10 kg'])->not->toContain('in any pack size')
        ->and($asked['Hill’s Sterilised Eend at medpets.nl'])->toContain('in any pack size')
        ->and($asked['Hill’s Sterilised Eend at brekz.nl'])->toContain('in any pack size')
        ->and(Http::recorded(static fn (Request $request): bool => $request->url() === TypeSafeClient::ENDPOINT))->toHaveCount(1);
});

test('a Klarna page that cannot be fetched counts an attempt each time and finishes at the cap', function (): void {
    fakeKlarnaWorld([], klarnaStatus: 403);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl()]);
    $seen = [];

    foreach (range(1, 3) as $attempt) {
        app(KlarnaDiscovery::class)->readLeads($product, 0);
        $discovery = WebDiscovery::query()->findOrFail($product->id);
        $seen[] = [$discovery->klarna_attempts, $discovery->klarna_leads, $discovery->klarna_checked_at !== null];
    }

    expect($seen)->toBe([[1, null, false], [2, null, false], [3, null, true]]);
});

test('a suggestion of another product is ignored: Add probes the link it was given, with no variant', function (): void {
    $agradi = 'https://www.agradi.nl/products/cavalor-muscle-motion';
    fakeKlarnaWorld([], [brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69')]);
    $product = klarnaProduct();
    $other = klarnaProduct();
    $foreign = storedLeadFinding($other, $agradi, 'agradi.nl', WebFindingStatus::Proposed, ['variant_key' => '44768357', 'add_url' => $agradi . '?variant=44768357']);
    $this->actingAs($product->user()->sole());

    Livewire\Livewire::test(AddShop::class, ['product' => $product])
        ->call('useSuggestion', brekzLeadUrl(), app(ProbeShopUrl::class), $foreign->id)
        ->assertSet('url', brekzLeadUrl())
        ->assertSet('chosenVariantKey', null)
        ->assertSet('snapshot.price', '85.69');

    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'agradi.nl'));
});

/**
 * A product with a lead finding of an earlier generation, at the start of a
 * new source stage.
 *
 * @return array{0: Product, 1: int}
 */
function klarnaSourceStage(): array
{
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    storedLeadFinding($product, brekzLeadUrl(), 'brekz.nl', WebFindingStatus::Proposed);

    return [$product, KlarnaSource::restart($product)];
}

test('an AI check that fails while finding the Klarna page counts an attempt and keeps the lead findings', function (): void {
    fakeKlarnaWorld(['site:klarna.com' => [klarnaResult('Hill’s 10kg Young Adult Sterilised met Eend', klarnaSourceUrl())]], typeSafe: static fn () => Http::response([], 500));
    [$product, $generation] = klarnaSourceStage();

    app(KlarnaDiscovery::class)->findPage($product, $generation);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_attempts)->toBe(1)
        ->and($discovery->klarna_url)->toBeNull()
        ->and($discovery->klarna_checked_at)->toBeNull()
        ->and(WebShopFinding::query()->whereNotNull('lead_url')->count())->toBe(1);
});

test('an AI answer that leaves a Klarna result out, with none passing, counts an attempt instead of ending the search', function (): void {
    fakeKlarnaWorld(
        ['site:klarna.com' => [
            klarnaResult('Hill’s 10kg Young Adult Sterilised met Eend', klarnaSourceUrl()),
            klarnaResult('Hill’s 7kg Young Adult Sterilised met Eend', 'https://www.klarna.com/nl/shopping/pl/cl456/222/Huisdieren/Hill-s-7kg/'),
        ]],
        // Only the first result is answered, below read_from.
        typeSafe: static fn (Request $request) => Http::response(['answers' => [(string) array_key_first(requestedQuestions($request)) => ['noul' => 0.1]]]),
    );
    [$product, $generation] = klarnaSourceStage();

    app(KlarnaDiscovery::class)->findPage($product, $generation);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_attempts)->toBe(1)
        ->and($discovery->klarna_url)->toBeNull()
        ->and($discovery->klarna_checked_at)->toBeNull()
        ->and(WebShopFinding::query()->whereNotNull('lead_url')->count())->toBe(1);
});

test('Klarna results that are all answered below read_from end the search with no page and drop the lead findings', function (): void {
    fakeKlarnaWorld(['site:klarna.com' => [
        klarnaResult('Hill’s Kitten Kip', klarnaKittenUrl()),
        klarnaResult('Hill’s Kitten Tonijn', 'https://www.klarna.com/nl/shopping/pl/cl456/333/Huisdieren/Hill-s-Kitten-Tonijn/'),
    ]]);
    [$product, $generation] = klarnaSourceStage();

    app(KlarnaDiscovery::class)->findPage($product, $generation);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_attempts)->toBe(0)
        ->and($discovery->klarna_url)->toBeNull()
        ->and($discovery->klarna_checked_at)->not->toBeNull()
        ->and(WebShopFinding::query()->whereNotNull('lead_url')->count())->toBe(0);
});

test('a lookup job that crashes counts against its lead until the lead fails', function (): void {
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl(), 'klarna_leads' => json_encode([
        ['host' => 'medpets.nl', 'title' => 'Hill’s 10 kg', 'pack_quantity' => 10000.0, 'pack_unit' => 'g', 'state' => 'pending', 'attempts' => 0],
    ])]);
    $seen = [];

    foreach (range(1, 3) as $attempt) {
        new LookUpKlarnaLead((string) $product->id, 0, 'medpets.nl')->failed();
        $discovery = WebDiscovery::query()->findOrFail($product->id);
        $seen[] = [$discovery->klarna_leads[0]['attempts'] ?? null, $discovery->klarna_leads[0]['state'] ?? null, $discovery->klarna_checked_at !== null];
    }

    expect($seen)->toBe([[1, 'pending', false], [2, 'pending', false], [3, 'failed', true]]);
});

test('a Klarna page search job that crashes counts an attempt', function (): void {
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);

    new FindKlarnaPage((string) $product->id, 0)->failed();

    expect(WebDiscovery::query()->findOrFail($product->id)->klarna_attempts)->toBe(1);
});

test('a lead with no size in its title is kept, and beats another size at the same shop', function (): void {
    // Brekz' 10 kg offer loses its size; its 2 x 10 kg offer keeps one.
    $html = str_replace('kattenvoer 10 kg"', 'kattenvoer"', File::get(base_path('tests/Fixtures/klarna/hills_sterilised_10kg.html')));
    fakeKlarnaWorld([], klarnaHtml: $html);
    Queue::fake([LookUpKlarnaLead::class]);
    $product = klarnaProduct();
    WebDiscovery::markQueued($product);
    WebDiscovery::query()->whereKey($product->id)->update(['klarna_url' => klarnaSourceUrl()]);

    app(KlarnaDiscovery::class)->readLeads($product, 0);

    $leads = WebDiscovery::query()->findOrFail($product->id)->klarna_leads ?? [];
    expect(array_column($leads, 'host'))->toBe(['medpets.nl', 'brekz.nl'])
        ->and($leads[1]['title'])->toBe("Hill's Adult Sterilised Cat met eend kattenvoer")
        ->and($leads[1]['pack_quantity'])->toBeNull()
        ->and($leads[1]['pack_unit'])->toBeNull();
});

test('another page on a shop already read takes no lead read on a later first check', function (): void {
    config()->set('dipcatch.web_discovery.klarna_leads_per_product', 2);
    fakeKlarnaWorld(['Hill’s Young Adult' => []]);
    Queue::fake();
    $product = klarnaProduct();
    storedLeadFinding($product, medpetsLeadUrl(), 'medpets.nl', WebFindingStatus::Proposed, ['first_chance' => 0.9]);
    storedLeadFinding($product, 'https://www.medpets.nl/hills-andere-pagina', 'medpets.nl', WebFindingStatus::Rejected, ['first_chance' => 0.9, 'failure' => 'other_page_on_host']);
    $brekz = storedLeadFinding($product, brekzLeadUrl(), 'brekz.nl', WebFindingStatus::New);

    discoverKlarna($product);

    // Two lead reads; Medpets used one, so Brekz gets the other.
    expect($brekz->refresh()->status)->toBe(WebFindingStatus::PendingRead)
        ->and($brekz->failure)->toBeNull();
});

test('a Klarna page the product tracks is found again as the same page and keeps its lead findings', function (): void {
    fakeKlarnaWorld(happyKlarnaSearches(), [
        medpetsLeadUrl() => medpetsPage(),
        brekzLeadUrl() => leadPage('Hill’s Adult Sterilised Cat met eend kattenvoer 10 kg', '85.69'),
    ]);
    $product = klarnaProduct();
    discoverKlarna($product);
    expect(WebShopFinding::query()->whereNotNull('lead_url')->where('status', WebFindingStatus::Proposed)->count())->toBe(2);

    Queue::fake([ReadKlarnaLeads::class]);
    Shop::factory()->for($product)->create(['url' => klarnaSourceUrl(), 'kind' => 'reference', 'unreadable_reason' => 'not_a_shop', 'current_price' => null]);
    $generation = KlarnaSource::restart($product);

    app(KlarnaDiscovery::class)->findPage($product->refresh()->load('shops', 'user'), $generation);

    $discovery = WebDiscovery::query()->findOrFail($product->id);
    expect($discovery->klarna_url)->toBe(klarnaSourceUrl())
        ->and($discovery->klarna_search_id)->toBeNull()
        ->and(WebShopFinding::query()->whereNotNull('lead_url')->where('status', WebFindingStatus::Proposed)->count())->toBe(2);
});

test('a Klarna page in the product’s open search is the source without a Klarna search', function (): void {
    Queue::fake([ReadKlarnaLeads::class]);
    fakeKlarnaWorld([
        'site:klarna.com' => [],
        'Hill’s Young Adult' => [klarnaResult('Hill’s 10kg Young Adult Sterilised met Eend • Klarna', klarnaSourceUrl())],
    ]);
    $product = klarnaProduct();

    discoverKlarna($product);

    expect(WebDiscovery::query()->findOrFail($product->id)->klarna_url)->toBe(klarnaSourceUrl())
        ->and(WebShopFinding::query()->where('host', 'klarna.com')->exists())->toBeFalse();
    Http::assertNotSent(static fn (Request $request): bool => str_contains(serperQuery($request), 'site:klarna.com'));
    Queue::assertPushed(ReadKlarnaLeads::class, 1);
});
