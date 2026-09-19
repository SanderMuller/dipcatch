<?php declare(strict_types=1);

use App\Console\Commands\RefreshCheckjebonDatasetCommand;
use App\Models\CheckjebonChain;
use App\Models\CheckjebonPrice;
use App\Services\Checkjebon\CheckjebonSource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Trimmed replica of checkjebon.nl's supermarkets.json (observed 2026-08-31).
 *
 * @param  list<mixed>|null  $ah
 * @param  list<mixed>|null  $dirk
 * @param  list<mixed>|null  $lidl
 */
function checkjebonFixture(?array $ah = null, ?array $dirk = null, ?array $lidl = null): string
{
    $ah ??= [
        ['n' => 'AH Kruiden roomkaas', 'l' => 'wi257/ah-kruiden-roomkaas', 'p' => 1.25, 's' => '125 g'],
        ['n' => '7up Regular', 'l' => 'wi195828/7up-regular', 'p' => 1.55, 's' => '0,5 l'],
    ];
    $dirk ??= [
        ['n' => 'Heineken Pilsener krat', 'l' => '6', 'p' => 13.84, 's' => '24 x 300 ml'],
    ];
    $lidl ??= [
        ['n' => 'Vlies filterzakken', 'l' => '8128671', 'p' => 6.99, 's' => ''],
    ];

    // One row per remaining chain: the importer follows the payload, so a
    // chain the dataset declares must arrive without any code change. Aldi
    // and Ekoplaza ship empty upstream, as they do today, and import nothing.
    $others = [
        'dekamarkt' => ['https://www.dekamarkt.nl/boodschappen/x/x/x/', 'DekaMarkt', '444852'],
        'hoogvliet' => ['https://www.hoogvliet.com/product/', 'Hoogvliet', 'beemster-belegen-30-plakken'],
        'plus' => ['https://www.plus.nl/product/', 'PLUS', 'beemster-belegen-48-plakken-kg-200-g-365529'],
        'poiesz' => ['https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/', 'Poiesz', '589247'],
        'spar' => ['https://www.spar.nl/', 'SPAR', 'beemster-kaassnack-9258359/'],
        'vomar' => ['https://www.vomar.nl/producten/', 'Vomar', 'bier-wijn-sterke-drank/x/x/304577'],
    ];

    $chains = [
        ['n' => 'ah', 'u' => 'https://www.ah.nl/producten/product/', 'c' => 'AH', 'd' => $ah],
        ['n' => 'aldi', 'u' => 'https://www.aldi.nl/producten/', 'c' => 'ALDI', 'd' => []],
        ['n' => 'dirk', 'u' => 'https://www.dirk.nl/boodschappen/x/x/x/', 'c' => 'Dirk', 'd' => $dirk],
        ['n' => 'ekoplaza', 'u' => 'https://www.ekoplaza.nl/producten/product/', 'c' => 'Ekoplaza', 'd' => []],
        ['n' => 'jumbo', 'u' => 'https://www.jumbo.com/producten/', 'c' => 'Jumbo', 'd' => [
            ['n' => 'Jumbo item', 'l' => 'jumbo-item-123456DSL', 'p' => 2.09, 's' => ''],
        ]],
        ['n' => 'lidl', 'u' => 'https://boodschaapje.nl/product/', 'c' => 'Lidl (via boodschaapje.nl)', 'd' => $lidl],
    ];

    foreach ($others as $chain => [$baseUrl, $label, $link]) {
        $chains[] = ['n' => $chain, 'u' => $baseUrl, 'c' => $label, 'd' => [
            ['n' => ucfirst($chain) . ' item', 'l' => $link, 'p' => 3.39, 's' => '150 g'],
        ]];
    }

    return json_encode($chains, JSON_THROW_ON_ERROR);
}

function checkjebonUrl(): string
{
    return 'https://raw.githubusercontent.com/supermarkt/checkjebon/main/data/supermarkets.json';
}

test('imports every chain with rows, using per-chain external ids', function (): void {
    Http::fake([checkjebonUrl() => Http::response(checkjebonFixture())]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    expect(CheckjebonPrice::query()->pluck('supermarket')->unique()->sort()->values()->all())
        ->toBe(['ah', 'dekamarkt', 'dirk', 'hoogvliet', 'jumbo', 'lidl', 'plus', 'poiesz', 'spar', 'vomar'])
        ->and(CheckjebonPrice::query()->count())->toBe(11)
        ->and(CheckjebonPrice::query()->where('supermarket', 'aldi')->exists())->toBeFalse()
        ->and(CheckjebonPrice::query()->where('supermarket', 'ekoplaza')->exists())->toBeFalse();

    $roomkaas = CheckjebonPrice::query()->where('supermarket', 'ah')->where('external_id', 'wi257')->first();
    expect($roomkaas)->not->toBeNull()
        ->and($roomkaas->name)->toBe('AH Kruiden roomkaas')
        ->and((string) $roomkaas->price)->toBe('1.25')
        ->and($roomkaas->size)->toBe('125 g')
        ->and(CheckjebonPrice::query()->where('supermarket', 'lidl')->where('external_id', '8128671')->exists())->toBeTrue();

    // Match-only chains keep the raw link as their id, slug or number alike.
    expect(CheckjebonPrice::query()->where('supermarket', 'jumbo')->value('external_id'))->toBe('jumbo-item-123456DSL')
        ->and(CheckjebonPrice::query()->where('supermarket', 'dirk')->value('external_id'))->toBe('6')
        ->and(CheckjebonPrice::query()->where('external_id', '8128671')->value('size'))->toBeNull();
});

test('stores the raw link and the chain metadata the URL is built from', function (): void {
    Http::fake([checkjebonUrl() => Http::response(checkjebonFixture())]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    expect(CheckjebonPrice::query()->where('external_id', 'wi257')->value('link'))
        ->toBe('wi257/ah-kruiden-roomkaas');

    $jumbo = CheckjebonChain::query()->where('chain', 'jumbo')->firstOrFail();

    expect($jumbo->label)->toBe('Jumbo')
        ->and($jumbo->base_url)->toBe('https://www.jumbo.com/producten/')
        ->and($jumbo->productUrl('jumbo-item-123456DSL'))
        ->toBe('https://www.jumbo.com/producten/jumbo-item-123456DSL');
});

test('a chain the app has never heard of is imported from the payload alone', function (): void {
    Http::fake([checkjebonUrl() => Http::response(json_encode([
        ['n' => 'newchain', 'u' => 'https://www.newchain.nl/p/', 'c' => 'NewChain', 'd' => [
            ['n' => 'New item', 'l' => 'new-item-1', 'p' => 1.99, 's' => '100 g'],
        ]],
    ], JSON_THROW_ON_ERROR))]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    expect(CheckjebonPrice::query()->where('supermarket', 'newchain')->value('external_id'))->toBe('new-item-1')
        ->and(CheckjebonChain::query()->where('chain', 'newchain')->value('base_url'))->toBe('https://www.newchain.nl/p/');
});

test('an empty chain gets no metadata, so an empty upstream records no chain', function (): void {
    Http::fake([checkjebonUrl() => Http::response(checkjebonFixture())]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    expect(CheckjebonChain::query()->whereIn('chain', ['aldi', 'ekoplaza'])->exists())->toBeFalse()
        ->and(CheckjebonChain::query()->count())->toBe(10);
});

test('importing more chains does not extend the pricing path', function (): void {
    Http::fake([checkjebonUrl() => Http::response(checkjebonFixture())]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    $source = app(CheckjebonSource::class);

    expect($source->supports('ah.nl'))->toBeTrue()
        ->and($source->supports('boodschaapje.nl'))->toBeTrue()
        ->and($source->supports('jumbo.com'))->toBeFalse()
        ->and($source->supports('plus.nl'))->toBeFalse()
        ->and($source->supports('dirk.nl'))->toBeFalse();
});

test('re-run upserts changed prices and prunes delisted rows', function (): void {
    Http::fake([checkjebonUrl() => Http::sequence()
        ->push(checkjebonFixture())
        ->push(checkjebonFixture(ah: [
            ['n' => 'AH Kruiden roomkaas', 'l' => 'wi257/ah-kruiden-roomkaas', 'p' => 0.99, 's' => '125 g'],
            // wi195828 (7up) delisted upstream.
        ]))]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();
    $this->travel(1)->days();
    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    expect((string) CheckjebonPrice::query()->where('external_id', 'wi257')->firstOrFail()->price)->toBe('0.99')
        ->and(CheckjebonPrice::query()->where('external_id', 'wi195828')->exists())->toBeFalse()
        ->and(CheckjebonPrice::query()->where('supermarket', 'lidl')->count())->toBe(1);
});

test('an empty supermarket upstream keeps its existing rows', function (): void {
    Http::fake([checkjebonUrl() => Http::sequence()
        ->push(checkjebonFixture())
        ->push(checkjebonFixture(lidl: []))]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();
    $this->travel(1)->days();
    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    // Lidl came back empty — its rows stay; AH refreshed normally.
    expect(CheckjebonPrice::query()->where('supermarket', 'lidl')->where('external_id', '8128671')->exists())->toBeTrue();
});

test('fetch failure keeps rows and exits non-zero', function (): void {
    Http::fake([checkjebonUrl() => Http::sequence()
        ->push(checkjebonFixture())
        ->push('nope', 500)]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();
    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertFailed();

    expect(CheckjebonPrice::query()->count())->toBe(11);
});

test('invalid JSON keeps rows and exits non-zero', function (): void {
    Http::fake([checkjebonUrl() => Http::sequence()
        ->push(checkjebonFixture())
        ->push('{broken')]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();
    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertFailed();

    expect(CheckjebonPrice::query()->count())->toBe(11);
});

test('malformed product rows are skipped, valid ones imported', function (): void {
    Http::fake([checkjebonUrl() => Http::response(checkjebonFixture(ah: [
        ['n' => 'Good', 'l' => 'wi1/good', 'p' => 1.00, 's' => ''],
        ['n' => 'No wi id', 'l' => 'not-a-wi-link', 'p' => 2.00, 's' => ''],
        ['n' => 'Missing price', 'l' => 'wi2/missing-price', 's' => ''],
        'not-an-array',
    ]))]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    expect(CheckjebonPrice::query()->where('supermarket', 'ah')->pluck('external_id')->all())->toBe(['wi1']);
});

test('a chain is recorded only after its prices are stored', function (): void {
    // The invariant is "a chain row implies that chain has prices", and the
    // write order is what holds it: a run that dies between the two writes
    // must not leave the chain behind. A crash cannot be simulated here —
    // the suite wraps each test in a transaction, and a failed statement
    // aborts the whole one — so the order itself is the assertion.
    Http::fake([checkjebonUrl() => Http::response(json_encode([
        ['n' => 'newchain', 'u' => 'https://www.newchain.nl/p/', 'c' => 'NewChain', 'd' => [
            ['n' => 'New item', 'l' => 'new-item-1', 'p' => 1.99, 's' => '100 g'],
        ]],
    ], JSON_THROW_ON_ERROR))]);

    $writes = [];

    // The verb matters as much as the table. `pruneChainsWithoutPrices()`
    // ends every run with a delete against `checkjebon_chains`, so asking
    // only which table was touched last says nothing about where the upsert
    // went.
    // Anchored on the statement's own target, not on any mention of the
    // table: the cleanup's `where chain not in (select ... from
    // checkjebon_prices)` names both tables in one delete.
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^(insert into|update|delete from) "(checkjebon_\w+)"/', $query->sql, $match) === 1) {
            $writes[] = $match[1] . ' ' . $match[2];
        }
    });

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    $chainUpserts = array_keys($writes, 'insert into checkjebon_chains', true);
    $priceWrites = [
        ...array_keys($writes, 'insert into checkjebon_prices', true),
        ...array_keys($writes, 'delete from checkjebon_prices', true),
    ];

    $lastPriceWrite = $priceWrites === [] ? -1 : max($priceWrites);

    // Exactly one, so "write it early for the log line and again at the end"
    // cannot satisfy this while reopening the window it exists to close.
    expect($lastPriceWrite)->toBeGreaterThan(-1)
        ->and($chainUpserts)->toHaveCount(1)
        ->and($chainUpserts[0])->toBeGreaterThan($lastPriceWrite);
});

test('every recorded chain has prices after a successful run', function (): void {
    // The end state, not the proof: both write orders satisfy this on a run
    // that finishes. The order test above is what pins the mechanism; this
    // one catches a later change that reintroduces the state some other way.
    Http::fake([checkjebonUrl() => Http::response(checkjebonFixture())]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    $withPrices = CheckjebonPrice::query()->distinct()->pluck('supermarket')->all();

    expect(CheckjebonChain::query()->count())->toBeGreaterThan(0)
        ->and(CheckjebonChain::query()->whereNotIn('chain', $withPrices)->pluck('chain')->all())->toBeEmpty();
});

test('a chain row left without prices by an older run is cleared', function (): void {
    // The reorder stops new orphans; it cannot repair one already stored.
    // Nothing reports this row any more, and a chain that stays empty
    // upstream is skipped by every later run, so the refresh clears it.
    CheckjebonChain::query()->create([
        'chain' => 'ghostchain',
        'label' => 'GhostChain',
        'base_url' => 'https://www.ghostchain.nl/p/',
        'refreshed_at' => now()->subDay(),
    ]);

    Http::fake([checkjebonUrl() => Http::response(checkjebonFixture())]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    expect(CheckjebonChain::query()->where('chain', 'ghostchain')->exists())->toBeFalse()
        ->and(CheckjebonChain::query()->where('chain', 'ah')->exists())->toBeTrue();
});
