<?php declare(strict_types=1);

use App\Console\Commands\RefreshCheckjebonDatasetCommand;
use App\Models\CheckjebonPrice;
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

    return json_encode([
        ['n' => 'ah', 'u' => 'https://www.ah.nl/producten/product/', 'c' => 'AH', 'd' => $ah],
        ['n' => 'aldi', 'u' => 'https://www.aldi.nl/producten/', 'c' => 'ALDI', 'd' => []],
        ['n' => 'dirk', 'u' => 'https://www.dirk.nl/boodschappen/x/x/x/', 'c' => 'Dirk', 'd' => $dirk],
        ['n' => 'jumbo', 'u' => 'https://www.jumbo.com/producten/', 'c' => 'Jumbo', 'd' => [
            ['n' => 'Jumbo item', 'l' => 'jumbo-item-123456DSL', 'p' => 2.09, 's' => ''],
        ]],
        ['n' => 'lidl', 'u' => 'https://boodschaapje.nl/product/', 'c' => 'Lidl (via boodschaapje.nl)', 'd' => $lidl],
    ], JSON_THROW_ON_ERROR);
}

function checkjebonUrl(): string
{
    return 'https://raw.githubusercontent.com/supermarkt/checkjebon/main/data/supermarkets.json';
}

test('imports AH, Dirk, and Lidl rows with per-host external ids', function (): void {
    Http::fake([checkjebonUrl() => Http::response(checkjebonFixture())]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    expect(CheckjebonPrice::query()->count())->toBe(4);

    $roomkaas = CheckjebonPrice::query()->where('supermarket', 'ah')->where('external_id', 'wi257')->first();
    expect($roomkaas)->not->toBeNull()
        ->and($roomkaas->name)->toBe('AH Kruiden roomkaas')
        ->and((string) $roomkaas->price)->toBe('1.25')
        ->and($roomkaas->size)->toBe('125 g');

    expect(CheckjebonPrice::query()->where('supermarket', 'dirk')->where('external_id', '6')->exists())->toBeTrue()
        ->and(CheckjebonPrice::query()->where('supermarket', 'lidl')->where('external_id', '8128671')->exists())->toBeTrue();

    // Jumbo is scraped directly — never imported. Empty size becomes null.
    expect(CheckjebonPrice::query()->where('supermarket', 'jumbo')->exists())->toBeFalse()
        ->and(CheckjebonPrice::query()->where('external_id', '8128671')->value('size'))->toBeNull();
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
        ->and(CheckjebonPrice::query()->where('supermarket', 'dirk')->count())->toBe(1);
});

test('an empty supermarket upstream keeps its existing rows', function (): void {
    Http::fake([checkjebonUrl() => Http::sequence()
        ->push(checkjebonFixture())
        ->push(checkjebonFixture(dirk: []))]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();
    $this->travel(1)->days();
    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();

    // Dirk came back empty — its rows stay; AH/Lidl refreshed normally.
    expect(CheckjebonPrice::query()->where('supermarket', 'dirk')->where('external_id', '6')->exists())->toBeTrue();
});

test('fetch failure keeps rows and exits non-zero', function (): void {
    Http::fake([checkjebonUrl() => Http::sequence()
        ->push(checkjebonFixture())
        ->push('nope', 500)]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();
    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertFailed();

    expect(CheckjebonPrice::query()->count())->toBe(4);
});

test('invalid JSON keeps rows and exits non-zero', function (): void {
    Http::fake([checkjebonUrl() => Http::sequence()
        ->push(checkjebonFixture())
        ->push('{broken')]);

    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertSuccessful();
    $this->artisan(RefreshCheckjebonDatasetCommand::class)->assertFailed();

    expect(CheckjebonPrice::query()->count())->toBe(4);
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
