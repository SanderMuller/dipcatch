<?php declare(strict_types=1);

use App\Actions\Products\SearchProductsByName;
use App\Models\CatalogueLink;
use App\Models\CheckjebonChain;
use App\Models\User;
use App\Services\BolApi\BolCatalogClient;
use App\Services\ProductSearch\NameSearchResult;
use App\Services\ProductSearch\NameSearchResults;
use App\Services\ProductSearch\NameSearchSource;
use App\Services\Suggestions\QueryTokens;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Http::preventStrayRequests();
    seedChains();
});

function searchByName(string $text, ?User $user = null, ?bool $trigrams = null): NameSearchResults
{
    $action = $trigrams === null
        ? app(SearchProductsByName::class)
        : new SearchProductsByName(app(BolCatalogClient::class), trigrams: $trigrams);

    return $action($user ?? User::factory()->create(), $text);
}

/**
 * @return list<string>
 */
function resultTitles(NameSearchResults $results): array
{
    return array_map(static fn (NameSearchResult $result): string => $result->title, $results->items);
}

function configureBol(): void
{
    config()->set('services.bol.api.client_id', 'client-id');
    config()->set('services.bol.api.client_secret', 'client-secret');
    CheckjebonChain::query()->create(['chain' => 'bol', 'label' => 'bol.com', 'base_url' => 'https://www.bol.com/nl/nl/p/', 'refreshed_at' => now()]);
}

function bolSearchCalls(): int
{
    return count(Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/products/search')));
}

test('a row that holds every word comes first, even when other rows overlap more', function (): void {
    foreach (['ah', 'dirk', 'jumbo', 'lidl', 'spar', 'vomar', 'poiesz'] as $chain) {
        seedRow($chain, 'Melk', null);
        seedRow($chain, 'Melk vol', null);
    }

    seedRow('ah', 'AH Halfvolle melk biologisch weidemelk pak', '1 l');

    $results = searchByName('ah melk');

    expect($results->items)->toHaveCount(8)
        ->and($results->items[0]->title)->toBe('AH Halfvolle melk biologisch weidemelk pak')
        ->and($results->fullMatch)->toBeTrue();
});

test('a row with every word is kept when more than 50 rows hold one of them', function (): void {
    foreach (range(1, 60) as $i) {
        seedRow('ah', "Melk variant {$i}", null);
    }

    seedRow('jumbo', 'Boerderij boer melk', null);

    $results = searchByName('boer melk');

    expect($results->items[0]->title)->toBe('Boerderij boer melk')
        ->and($results->items)->toHaveCount(8)
        ->and($results->fullMatch)->toBeTrue();
});

test('the database returns 50 rows at most, ordered by similarity when pg_trgm is there', function (bool $trigrams, bool $fullMatch): void {
    // Inserted first, so they come first by id.
    foreach (range(1, 55) as $i) {
        seedRow('ah', "Melkchocolade reep {$i}", null);
    }

    seedRow('jumbo', 'Volle melk', null);

    $results = searchByName('melk', trigrams: $trigrams);

    // Without similarity the 56th row by id never reaches PHP.
    expect($results->items)->toHaveCount(8)
        ->and($results->fullMatch)->toBe($fullMatch)
        ->and(in_array('Volle melk', resultTitles($results), strict: true))->toBe($fullMatch);
})->with([
    'with pg_trgm' => [true, true],
    'without pg_trgm' => [false, false],
]);

test('a typed accent matches names with and without it', function (): void {
    seedRow('ah', 'Café koffiebonen', null);
    seedRow('jumbo', 'Cafe koffiebonen', null);

    // The accented word alone, so only its ASCII form can match "Cafe".
    expect(resultTitles(searchByName('café')))
        ->toEqualCanonicalizing(['Café koffiebonen', 'Cafe koffiebonen']);
});

test('a size in the text must match the row size to count as every word', function (string $size, bool $fullMatch): void {
    seedRow('ah', 'Halfvolle melk', $size);

    $results = searchByName('halfvolle melk 1 l');

    expect(resultTitles($results))->toBe(['Halfvolle melk'])
        ->and($results->fullMatch)->toBe($fullMatch);
})->with([
    'same size in its own column' => ['1 l', true],
    'same size in other words' => ['1000 ml', true],
    'another size' => ['2 l', false],
]);

test('a word only covers a whole word of the name', function (): void {
    seedRow('ah', 'Roomijs vanille', null);

    $results = searchByName('room');

    expect(resultTitles($results))->toBe(['Roomijs vanille'])
        ->and($results->fullMatch)->toBeFalse()
        ->and(QueryTokens::ofSearchText('room')->coveredBy(QueryTokens::ofCatalogueRow('Room', size: null)))->toBeTrue();
});

test('three-character words and digits are needles', function (): void {
    seedRow('ah', '7up Original fles', '1,5 l');

    expect(resultTitles(searchByName('7up')))->toBe(['7up Original fles']);
});

test('text without a word of three characters finds no catalogue rows, and still asks bol.com', function (string $text): void {
    configureBol();
    fakeBolApi([BolCatalogClient::BASE_URL . '/products/search*' => Http::response(['results' => []])]);
    seedRow('ah', 'AH 1 ei', null);
    seedRow('ah', '100% sinaasappelsap', null);

    $results = searchByName($text);

    expect($results->items)->toBeEmpty()
        ->and($results->fullMatch)->toBeFalse()
        ->and($results->bolFailed)->toBeFalse()
        ->and(bolSearchCalls())->toBe(1);
})->with([
    'short words' => ['ah 1'],
    // The word split drops % and _, so no LIKE pattern is built from them.
    'like wildcards' => ['%% __'],
]);

test('only the first ten words of the text are needles', function (): void {
    seedRow('ah', 'Pindakaas naturel', null);

    $tenWords = 'alfa bravo charlie delta echo foxtrot golf hotel india juliett';

    expect(resultTitles(searchByName("pindakaas {$tenWords}")))->toBe(['Pindakaas naturel'])
        ->and(resultTitles(searchByName("{$tenWords} pindakaas")))->toBeEmpty();
});

test('a page a recent check found gone is not listed, and a live page lists its own URL', function (): void {
    $gone = seedRow('ah', 'Pindakaas naturel', null);
    $goneLongAgo = seedRow('ah', 'Pindakaas stukjes', null);
    $moved = seedRow('jumbo', 'Pindakaas extra crunchy', null);
    CatalogueLink::record('ah', $gone->external_id, alive: false);
    CatalogueLink::query()->create([
        'chain' => 'ah',
        'external_id' => $goneLongAgo->external_id,
        'alive' => false,
        'url' => null,
        'checked_at' => now()->subDays(CatalogueLink::VALID_DAYS + 1),
    ]);
    CatalogueLink::record('jumbo', $moved->external_id, alive: true, url: 'https://www.jumbo.com/producten/pindakaas-extra-crunchy-123');

    $results = searchByName('pindakaas');

    expect(array_column(array_map(static fn (NameSearchResult $result): array => $result->toArray(), $results->items), 'url', 'title'))
        ->toEqualCanonicalizing([
            'Pindakaas stukjes' => 'https://www.ah.nl/producten/product/' . $goneLongAgo->link,
            'Pindakaas extra crunchy' => 'https://www.jumbo.com/producten/pindakaas-extra-crunchy-123',
        ]);
});

test('only fresh chains that can be tracked and linked are searched', function (): void {
    seedRow('ah', 'Pindakaas naturel', null, link: 'wi123/pindakaas-naturel');
    seedRow('dekamarkt', 'Pindakaas naturel', null);
    seedRow('hoogvliet', 'Pindakaas naturel', null);
    seedRow('jumbo', 'Pindakaas naturel', null, refreshedAt: now()->subHours(CheckjebonChain::MAX_AGE_HOURS + 1));

    $results = searchByName('pindakaas');

    expect(array_map(static fn (NameSearchResult $result): string => $result->shopLabel, $results->items))->toBe(['AH'])
        ->and($results->items[0]->toArray())->toMatchArray([
            'source' => 'catalogue',
            'host' => 'ah.nl',
            'url' => 'https://www.ah.nl/producten/product/wi123/pindakaas-naturel',
            'price' => '1.00',
        ]);
});

test('without a bol.com login there is no bol section and no error', function (): void {
    seedRow('ah', 'Pindakaas naturel', null);

    $results = searchByName('pindakaas');

    expect($results->bolFailed)->toBeFalse()
        ->and(resultTitles($results))->toBe(['Pindakaas naturel']);
    Http::assertNothingSent();
});

test('bol.com results follow the catalogue, and replace the feed row with the same barcode', function (): void {
    configureBol();
    seedRow('ah', 'Calvé Pindakaas 650 g', null);
    // The catalogue stores the barcode without leading zeros.
    seedRow('bol', 'Calve pindakaas pot', null, ean: '12345678905');
    seedRow('bol', 'Calve pindakaas knijpfles', null, ean: '8710000000001');
    fakeBolApi([BolCatalogClient::BASE_URL . '/products/search*' => Http::response(['results' => [
        bolProductJson('0012345678905', 'Calvé Pindakaas 650 g pot', '9200000001', 4.29) + ['image' => ['url' => 'https://media.s-bol.com/pot.jpg']],
    ]])]);

    $results = searchByName('calvé pindakaas');

    expect(resultTitles($results))->toBe(['Calve pindakaas knijpfles', 'Calvé Pindakaas 650 g', 'Calvé Pindakaas 650 g pot'])
        ->and(array_last($results->items)->toArray())->toMatchArray([
            'source' => NameSearchSource::Bol->value,
            'ean' => '12345678905',
            'price' => '4.29',
            'photoUrl' => 'https://media.s-bol.com/pot.jpg',
        ])
        ->and($results->bolFailed)->toBeFalse();
});

test('bol.com is asked once per text an hour', function (): void {
    configureBol();
    fakeBolApi([BolCatalogClient::BASE_URL . '/products/search*' => Http::response(['results' => [
        bolProductJson('8710000000001', 'Calve pindakaas', '9200000001', 4.29),
    ]])]);

    searchByName('Calvé pindakaas');
    $results = searchByName('  calvé   PINDAKAAS ');

    expect(resultTitles($results))->toBe(['Calve pindakaas'])
        ->and(bolSearchCalls())->toBe(1);

    $this->travel(61)->minutes();
    searchByName('calvé pindakaas');

    expect(bolSearchCalls())->toBe(2);
});

test('a bol.com failure shows the note, keeps the catalogue results, and is not kept', function (): void {
    configureBol();
    seedRow('ah', 'Pindakaas naturel', null);
    fakeBolApi([BolCatalogClient::BASE_URL . '/products/search*' => Http::sequence()
        ->push(['message' => 'Too many requests'], 429)
        ->push(['results' => [bolProductJson('8710000000001', 'Pindakaas bol', '9200000001', 3.00)]])]);

    $failed = searchByName('pindakaas');
    $retried = searchByName('pindakaas');

    expect($failed->bolFailed)->toBeTrue()
        ->and(resultTitles($failed))->toBe(['Pindakaas naturel'])
        ->and($retried->bolFailed)->toBeFalse()
        ->and(resultTitles($retried))->toBe(['Pindakaas naturel', 'Pindakaas bol']);
});

test('over the app-wide bol.com limit the bol part is skipped as failed', function (): void {
    configureBol();
    fakeBolApi([BolCatalogClient::BASE_URL . '/products/search*' => Http::response(['results' => []])]);

    foreach (range(1, 5) as $call) {
        RateLimiter::hit('name-search:bol', 1);
    }

    expect(searchByName('pindakaas')->bolFailed)->toBeTrue()
        ->and(bolSearchCalls())->toBe(0);
});

test('a user gets 20 searches a minute', function (): void {
    configureBol();
    fakeBolApi([BolCatalogClient::BASE_URL . '/products/search*' => Http::response(['results' => []])]);
    seedRow('ah', 'Pindakaas naturel', null);
    $user = User::factory()->create();

    foreach (range(1, 20) as $i) {
        expect(searchByName("pindakaas {$i}", $user)->throttled)->toBeFalse();
        // Past the app-wide bol.com limit of 5 a second.
        $this->travel(1)->seconds();
    }

    $throttled = searchByName('pindakaas 21', $user);

    expect($throttled->throttled)->toBeTrue()
        ->and($throttled->items)->toBeEmpty()
        ->and(bolSearchCalls())->toBe(20)
        ->and(searchByName('pindakaas 21')->throttled)->toBeFalse();
});

test('results survive the round trip through Livewire state', function (): void {
    configureBol();
    seedRow('ah', 'Pindakaas naturel', '350 g', ean: '8710000000002');
    fakeBolApi([BolCatalogClient::BASE_URL . '/products/search*' => Http::response(['results' => [
        bolProductJson('8710000000001', 'Pindakaas bol', '9200000001', 3.00),
    ]])]);

    $results = searchByName('pindakaas naturel');

    expect(NameSearchResults::fromArray($results->toArray()))->toEqual($results);
});
