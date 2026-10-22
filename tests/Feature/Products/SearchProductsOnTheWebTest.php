<?php declare(strict_types=1);

use App\Actions\Products\SearchProductsOnTheWeb;
use App\Models\User;
use App\Models\WebSearch;
use App\Services\ProductSearch\NameSearchResult;
use App\Services\ProductSearch\NameSearchResults;
use App\Services\ProductSearch\NameSearchSource;
use App\Services\ProductSearch\NameSearchVerdict;
use App\Services\ProductSearch\WebNameSearch;
use App\Services\ProductSearch\WebNameSearchStatus;
use App\Services\ShopDiscovery\SerperProvider;
use App\Services\ShopDiscovery\WebSearches;
use App\Services\TypeSafe\NameSearchQuestion;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config()->set('services.serper.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    Http::preventStrayRequests();
});

/** The organic results Serper answers with, or the status it fails with. */
function fakeNameSearchApis(array $organic = [['title' => 'Calvé Pindakaas 650 g', 'link' => 'https://www.shop.test/p/1', 'snippet' => 'Op voorraad']], int $serperStatus = 200, ?Closure $jev = null): void
{
    Http::fake([
        SerperProvider::ENDPOINT => Http::response(['organic' => $organic], $serperStatus),
        TypeSafeClient::ENDPOINT => $jev ?? Http::response([], 500),
    ]);
}

function jevSays(float $tooVague): Closure
{
    return static fn (): mixed => Http::response(['answers' => [TypeSafeClient::NAME_SEARCH_QUESTION => ['probabilities' => [
        NameSearchQuestion::TOO_VAGUE => $tooVague,
        NameSearchQuestion::SPECIFIC_PRODUCT => 1 - $tooVague,
    ]]]]);
}

function nameSearchProUser(bool $shopChecks = true): User
{
    config()->set('services.typesafe.key', 'test-key');
    $user = User::factory()->create(['shop_checks' => $shopChecks]);
    subscribeUser($user);

    return $user->refresh();
}

function searchTheWeb(User $user, string $typed = 'calvé pindakaas 650g', ?NameSearchResults $local = null, ?NameSearchVerdict $knownVerdict = null): WebNameSearch
{
    return app(SearchProductsOnTheWeb::class)($user, $typed, $local ?? new NameSearchResults(), $knownVerdict);
}

function serperRequests(): int
{
    return count(Http::recorded(static fn (Request $request): bool => $request->url() === SerperProvider::ENDPOINT));
}

function jevRequests(): int
{
    return count(Http::recorded(static fn (Request $request): bool => $request->url() === TypeSafeClient::ENDPOINT));
}

function webSearchKey(User $user): string
{
    return "name-search:web:{$user->id}:" . now($user->timezone)->toDateString();
}

function webSearchesUsed(User $user): mixed
{
    return RateLimiter::attempts(webSearchKey($user));
}

function storeWebSearch(string $query, array $results = []): WebSearch
{
    return WebSearch::query()->create(['query_hash' => WebSearch::hashOf($query, 'nl'), 'query' => WebSearch::normalise($query), 'results' => $results, 'searched_at' => now()]);
}

test('a new search runs, is stored under the stripped text, and costs one search', function (): void {
    fakeNameSearchApis();
    $user = User::factory()->create();

    $result = searchTheWeb($user, 'calvé "pindakaas" 650g site:ah.nl -jumbo');

    expect($result->status)->toBe(WebNameSearchStatus::Found)
        ->and($result->verdict)->toBe(NameSearchVerdict::Specific)
        ->and($result->items[0]->toArray())->toMatchArray(['source' => 'web', 'title' => 'Calvé Pindakaas 650 g', 'host' => 'shop.test', 'url' => 'https://www.shop.test/p/1', 'snippet' => 'Op voorraad'])
        ->and(webSearchesUsed($user))->toBe(1)
        ->and(WebSearch::query()->sole()->query)->toBe('calvé pindakaas 650g');
    Http::assertSent(static fn (Request $request): bool => $request->url() === SerperProvider::ENDPOINT && $request['q'] === 'calvé pindakaas 650g');
});

test('the search operators are stripped into one search text', function (string $typed, string $searchText): void {
    expect(SearchProductsOnTheWeb::searchTextOf($typed))->toBe($searchText);
})->with([
    'site and minus' => ['melk site:ah.nl -lidl', 'melk'],
    'other operators' => ['INURL:x pindakaas intitle:y filetype:pdf naturel', 'pindakaas naturel'],
    'quotes' => ['“calvé” "pindakaas"', 'calvé pindakaas'],
    'apostrophe and hyphen inside a word' => ["G'woon pinda-kaas", "G'woon pinda-kaas"],
    'only operators' => ['site:ah.nl', ''],
]);

test('text that is too short once stripped does not search', function (): void {
    fakeNameSearchApis();

    expect(searchTheWeb(User::factory()->create(), 'site:ah.nl "ab"')->status)->toBe(WebNameSearchStatus::TooShort);
    Http::assertNothingSent();
});

test('a name search does not read the key of a discovery site: query', function (): void {
    fakeNameSearchApis();
    storeWebSearch('site:ah.nl halfvolle melk', [['title' => 'AH', 'link' => 'https://www.ah.nl/p/1', 'snippet' => '', 'position' => 1]]);

    $result = searchTheWeb(User::factory()->create(), 'halfvolle melk site:ah.nl');

    expect($result->items[0]->url)->toBe('https://www.shop.test/p/1')
        ->and(serperRequests())->toBe(1);
});

test('a stored search shows at once, ungated and free, even at the cap', function (): void {
    fakeNameSearchApis();
    $user = User::factory()->create();
    storeWebSearch('melk', [['title' => 'Melk', 'link' => 'https://www.shop.test/melk', 'snippet' => '', 'position' => 1]]);

    foreach (range(1, 3) as $search) {
        RateLimiter::hit(webSearchKey($user), 86_400);
    }

    $result = searchTheWeb($user, 'Melk');

    expect($result->status)->toBe(WebNameSearchStatus::Found)
        ->and($result->items[0]->snippet)->toBeNull()
        ->and($result->verdict)->toBeNull()
        ->and(webSearchesUsed($user))->toBe(3)
        ->and(app(SearchProductsOnTheWeb::class)->isStored('melk  '))->toBeTrue()
        ->and(app(SearchProductsOnTheWeb::class)->capSpent($user))->toBeTrue();
    Http::assertNothingSent();
});

test('a free account gets 3 new searches a day and a Pro account 10', function (bool $pro, int $cap): void {
    fakeNameSearchApis();
    $user = $pro ? nameSearchProUser(shopChecks: false) : User::factory()->create();

    foreach (range(1, $cap) as $i) {
        expect(searchTheWeb($user, "calvé pindakaas {$i}")->status)->toBe(WebNameSearchStatus::Found);
    }

    expect(searchTheWeb($user, 'calvé pindakaas extra')->status)->toBe(WebNameSearchStatus::CapSpent)
        ->and(serperRequests())->toBe($cap)
        ->and(webSearchesUsed($user))->toBe($cap);
})->with([
    'free' => [false, 3],
    'pro' => [true, 10],
]);

test('the cap is counted per day in the account timezone', function (): void {
    fakeNameSearchApis();
    $this->travelTo('2026-10-22 14:30:00');
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);

    foreach (range(1, 3) as $i) {
        searchTheWeb($user, "calvé pindakaas {$i}");
    }

    expect(searchTheWeb($user, 'calvé pindakaas 4')->status)->toBe(WebNameSearchStatus::CapSpent);

    // 01:30 the next day in Tokyo, still the same day in UTC.
    $this->travelTo('2026-10-22 16:30:00');

    expect(searchTheWeb($user, 'calvé pindakaas 4')->status)->toBe(WebNameSearchStatus::Found);
});

test('a slot is reserved before the search, so a second text at once cannot take the last one', function (): void {
    $user = User::factory()->create();
    $key = webSearchKey($user);
    RateLimiter::hit($key, 86_400);
    RateLimiter::hit($key, 86_400);
    $during = null;

    Http::fake([SerperProvider::ENDPOINT => function () use ($user, &$during): mixed {
        $during = searchTheWeb($user, 'douwe egberts koffiebonen');

        return Http::response(['organic' => []]);
    }]);

    expect(searchTheWeb($user)->status)->toBe(WebNameSearchStatus::Found)
        ->and($during?->status)->toBe(WebNameSearchStatus::CapSpent)
        ->and(webSearchesUsed($user))->toBe(3)
        ->and(serperRequests())->toBe(1);
});

test('a second request for the same text while one runs returns at once', function (): void {
    $user = nameSearchProUser();
    $during = null;

    Http::fake([
        TypeSafeClient::ENDPOINT => jevSays(0.1),
        SerperProvider::ENDPOINT => function () use ($user, &$during): mixed {
            $during = searchTheWeb($user);

            return Http::response(['organic' => []]);
        },
    ]);

    expect(searchTheWeb($user)->status)->toBe(WebNameSearchStatus::Found)
        ->and($during?->status)->toBe(WebNameSearchStatus::Busy)
        ->and(jevRequests())->toBe(1)
        ->and(webSearchesUsed($user))->toBe(1);
});

test('another account searching the same text is not blocked by the lock', function (): void {
    fakeNameSearchApis();
    $other = User::factory()->create();
    Cache::lock("name-search:{$other->id}:" . hash('sha256', 'calvé pindakaas 650g'), 90)->get();

    expect(searchTheWeb($other)->status)->toBe(WebNameSearchStatus::Busy)
        ->and(searchTheWeb(User::factory()->create())->status)->toBe(WebNameSearchStatus::Found);
});

test('an empty answer that was stored is charged', function (): void {
    fakeNameSearchApis(organic: []);
    $user = User::factory()->create();

    $result = searchTheWeb($user);

    expect($result->status)->toBe(WebNameSearchStatus::Found)
        ->and($result->items)->toBe([])
        ->and(webSearchesUsed($user))->toBe(1);
});

test('a failed search is free', function (int $status): void {
    fakeNameSearchApis(serperStatus: $status);
    $user = User::factory()->create();

    expect(searchTheWeb($user)->status)->toBe(WebNameSearchStatus::Failed)
        ->and(webSearchesUsed($user))->toBe(0)
        ->and(WebSearch::query()->count())->toBe(0);
})->with([401, 402, 503]);

test('a failed save is free and shows the failure', function (): void {
    fakeNameSearchApis();
    $user = User::factory()->create();
    WebSearch::creating(static fn (): never => throw new QueryException('pgsql', 'insert into web_searches', [], new PDOException('disk full')));

    expect(searchTheWeb($user)->status)->toBe(WebNameSearchStatus::Failed)
        ->and(webSearchesUsed($user))->toBe(0);
});

test('a search another request stored inside the lock is free', function (): void {
    $user = nameSearchProUser();

    // Jev answers after the stored lookup and before the search: the other
    // request's result lands in between.
    Http::fake([
        TypeSafeClient::ENDPOINT => function (): mixed {
            storeWebSearch('calvé pindakaas 650g', [['title' => 'Stored', 'link' => 'https://www.shop.test/stored', 'snippet' => '', 'position' => 1]]);

            return jevSays(0.1)();
        },
        SerperProvider::ENDPOINT => Http::response(['organic' => []]),
    ]);

    $result = searchTheWeb($user);

    expect($result->items[0]->title)->toBe('Stored')
        ->and(serperRequests())->toBe(0)
        ->and(webSearchesUsed($user))->toBe(0);
});

test('the app-wide name search cap fails the search without a charge and leaves discovery its own', function (): void {
    fakeNameSearchApis();
    config()->set('dipcatch.web_discovery.name_search_daily_limit', 1);
    $user = User::factory()->create();

    searchTheWeb($user, 'calvé pindakaas 1');
    $result = searchTheWeb($user, 'calvé pindakaas 2');

    expect($result->status)->toBe(WebNameSearchStatus::Failed)
        ->and(webSearchesUsed($user))->toBe(1)
        ->and(Cache::get('web-discovery:searches:' . now()->toDateString()))->toBeNull()
        ->and(app(WebSearches::class)->forQuery('calvé pindakaas 2', 'nl'))->toBeInstanceOf(WebSearch::class);
});

test('only shop pages are shown', function (): void {
    fakeNameSearchApis(organic: [
        ['title' => 'Compare', 'link' => 'https://tweakers.net/pricewatch/1', 'snippet' => ''],
        ['title' => 'Klarna', 'link' => 'https://www.klarna.com/nl/shopping/pl/cl1/1/x/', 'snippet' => ''],
        ['title' => 'PLUS', 'link' => 'https://www.plus.nl/product/x', 'snippet' => ''],
        ['title' => 'Broken', 'link' => 'not a url', 'snippet' => ''],
        ['title' => 'Shop', 'link' => 'https://www.shop.test/p/1', 'snippet' => ''],
    ]);

    expect(array_map(static fn (NameSearchResult $result): string => $result->title, searchTheWeb(User::factory()->create())->items))->toBe(['Shop']);
});

test('the plain rule blocks one word or short words, without a charge', function (string $typed): void {
    fakeNameSearchApis();
    $user = User::factory()->create(['shop_checks' => true]);

    $result = searchTheWeb($user, $typed);

    expect($result->status)->toBe(WebNameSearchStatus::TooVague)
        ->and($result->verdict)->toBe(NameSearchVerdict::TooVague)
        ->and(webSearchesUsed($user))->toBe(0)
        ->and(RateLimiter::attempts("shop-check:name-search:user:{$user->id}"))->toBe(0);
    Http::assertNothingSent();
})->with([
    'one word' => ['melk'],
    'one word after stripping' => ['melk site:ah.nl'],
    'no word of four letters' => ['ah ijs 1'],
]);

test('the plain rule blocks text a catalogue result holds every word of', function (): void {
    fakeNameSearchApis();

    $result = searchTheWeb(User::factory()->create(), 'halfvolle melk', new NameSearchResults(fullMatch: true));

    expect($result->status)->toBe(WebNameSearchStatus::MatchesEveryWord);
    Http::assertNothingSent();
});

test('Jev decides for a Pro account with the shop check on', function (float $tooVague, WebNameSearchStatus $status): void {
    $user = nameSearchProUser();
    fakeNameSearchApis(jev: jevSays($tooVague));

    // One word: the plain rule would block it.
    $local = new NameSearchResults([new NameSearchResult(NameSearchSource::Catalogue, 'Nutella 630 g', 'AH', 'ah.nl', 'https://www.ah.nl/p/1')]);

    expect(searchTheWeb($user, 'nutella', $local)->status)->toBe($status)
        ->and(jevRequests())->toBe(1)
        ->and(webSearchesUsed($user))->toBe($status === WebNameSearchStatus::Found ? 1 : 0);
    Http::assertSent(static fn (Request $request): bool => $request->url() === TypeSafeClient::ENDPOINT
        && $request['state'] === ['typed_text' => 'nutella', 'local_results' => ['Nutella 630 g']]);
})->with([
    'specific' => [0.2, WebNameSearchStatus::Found],
    'too vague' => [0.5, WebNameSearchStatus::TooVague],
]);

test('the plain rule decides when Jev fails, answers without a verdict or has no budget left', function (string $case): void {
    $user = nameSearchProUser();
    fakeNameSearchApis(jev: match ($case) {
        'malformed' => static fn (): mixed => Http::response(['answers' => ['other' => []]]),
        default => static fn (): mixed => Http::response([], 500),
    });

    if ($case === 'budget') {
        config()->set('dipcatch.shop_checks.daily_limit_per_user', 1);
        RateLimiter::hit("shop-check:name-search:user:{$user->id}", 86_400);
    }

    expect(searchTheWeb($user, 'melk')->status)->toBe(WebNameSearchStatus::TooVague)
        ->and(searchTheWeb($user, 'calvé pindakaas')->status)->toBe(WebNameSearchStatus::Found)
        ->and(jevRequests())->toBe($case === 'budget' ? 0 : 2);
})->with(['failure', 'malformed', 'budget']);

test('a free account with the AI consent on never asks Jev', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    fakeNameSearchApis(jev: jevSays(0.9));

    expect(searchTheWeb(User::factory()->create(['shop_checks' => true]))->status)->toBe(WebNameSearchStatus::Found)
        ->and(jevRequests())->toBe(0);
});

test('a verdict the wizard kept is not asked again', function (): void {
    $user = nameSearchProUser();
    fakeNameSearchApis(jev: jevSays(0.9));

    expect(searchTheWeb($user, 'nutella', knownVerdict: NameSearchVerdict::Specific)->status)->toBe(WebNameSearchStatus::Found)
        ->and(searchTheWeb($user, 'koffie', knownVerdict: NameSearchVerdict::TooVague)->status)->toBe(WebNameSearchStatus::TooVague)
        ->and(jevRequests())->toBe(0);
});

test('a spent cap does not ask Jev', function (): void {
    $user = nameSearchProUser();
    fakeNameSearchApis(jev: jevSays(0.1));

    foreach (range(1, 10) as $search) {
        RateLimiter::hit(webSearchKey($user), 86_400);
    }

    expect(searchTheWeb($user)->status)->toBe(WebNameSearchStatus::CapSpent);
    Http::assertNothingSent();
});
