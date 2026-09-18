<?php declare(strict_types=1);

use App\Enums\CanaryOutcome;
use App\Models\AdapterCanaryResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The canary fetches one known product page per host adapter. These drive the
 * command against faked responses; it never reaches a real shop, which the
 * environment guard also enforces outside of tests.
 */
beforeEach(function (): void {
    config()->set('canary.adapters', ['bol' => 'https://www.bol.com/nl/nl/p/canary/']);
    config()->set('canary.price_move_pct', 50);
    config()->set('canary.environments', ['testing']);

    Cache::put('dipcatch:robots:bol.com', [], 3600);
});

/** A bol.com page the BolAdapter claims, priced as asked. */
function canaryPage(string $price = '20.00'): string
{
    return withJsonLd(json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Canary product',
        'offers' => [
            '@type' => 'Offer',
            'price' => $price,
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR));
}

function canaryRow(): AdapterCanaryResult
{
    return AdapterCanaryResult::query()->where('adapter', 'bol')->sole();
}

test('a page the adapter reads stores ok with its price', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::response(canaryPage('20.00'))]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    $row = canaryRow();

    expect($row->outcome)->toBe(CanaryOutcome::Ok)
        ->and((float) $row->price)->toBe(20.0)
        ->and((float) $row->last_ok_price)->toBe(20.0)
        ->and($row->observed_adapter)->toBe('bol')
        ->and($row->consecutive_unreachable)->toBe(0);
});

test('a page the adapter can no longer read stores rot', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::response('<html><body>redesigned</body></html>')]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    expect(canaryRow()->outcome)->toBe(CanaryOutcome::Rot);
});

test('a price that moved past the ceiling stores rot in both directions', function (string $second): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::sequence()
        ->push(canaryPage('20.00'))
        ->push(canaryPage($second))]);

    $this->artisan('dipcatch:canary')->assertSuccessful();
    $this->artisan('dipcatch:canary')->assertSuccessful();

    $row = canaryRow();

    // The baseline is the last good reading, so it does not advance to the
    // suspect one.
    expect($row->outcome)->toBe(CanaryOutcome::Rot)
        ->and((float) $row->last_ok_price)->toBe(20.0);
})->with([
    'halved' => '9.00',
    'doubled' => '40.00',
    'exactly at the ceiling' => '30.00',
]);

test('a move inside the ceiling stays ok and advances the baseline', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::sequence()
        ->push(canaryPage('20.00'))
        ->push(canaryPage('25.00'))]);

    $this->artisan('dipcatch:canary')->assertSuccessful();
    $this->artisan('dipcatch:canary')->assertSuccessful();

    $row = canaryRow();

    expect($row->outcome)->toBe(CanaryOutcome::Ok)
        ->and((float) $row->last_ok_price)->toBe(25.0);
});

test('a first run has no baseline and is judged on extraction alone', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::response(canaryPage('0.01'))]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    expect(canaryRow()->outcome)->toBe(CanaryOutcome::Ok);
});

test('a repointed entry drops the old products baseline', function (): void {
    Http::fake([
        'https://www.bol.com/nl/nl/p/canary/' => Http::response(canaryPage('20.00')),
        'https://www.bol.com/nl/nl/p/other/' => Http::response(canaryPage('120.00')),
    ]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    config()->set('canary.adapters', ['bol' => 'https://www.bol.com/nl/nl/p/other/']);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    // 120.00 against the old 20.00 would be rot; against no baseline it is a
    // first observation.
    $row = canaryRow();

    expect($row->outcome)->toBe(CanaryOutcome::Ok)
        ->and((float) $row->last_ok_price)->toBe(120.0);
});

test('a price of zero stores rot rather than becoming a baseline', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::response(canaryPage('0.00'))]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    $row = canaryRow();

    expect($row->outcome)->toBe(CanaryOutcome::Rot)
        ->and($row->last_ok_price)->toBeNull();
});

test('a page claimed by another adapter stores rot naming what answered', function (): void {
    config()->set('canary.adapters', ['jumbo' => 'https://www.bol.com/nl/nl/p/canary/']);
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::response(canaryPage('20.00'))]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    $row = AdapterCanaryResult::query()->where('adapter', 'jumbo')->sole();

    expect($row->outcome)->toBe(CanaryOutcome::Rot)
        ->and($row->observed_adapter)->toBe('bol')
        ->and($row->detail)->toContain('expected adapter jumbo');
});

test('a delisted product stores bad_url', function (int $status): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::response('gone', $status)]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    expect(canaryRow()->outcome)->toBe(CanaryOutcome::BadUrl);
})->with([404, 410]);

test('a malformed url stores bad_url without fetching', function (): void {
    config()->set('canary.adapters', ['bol' => 'not-a-url']);
    Http::fake();

    $this->artisan('dipcatch:canary')->assertSuccessful();

    expect(canaryRow()->outcome)->toBe(CanaryOutcome::BadUrl);
    Http::assertNothingSent();
});

test('a robots refusal stores bad_url', function (): void {
    Cache::put('dipcatch:robots:bol.com', [['type' => 'disallow', 'pattern' => '/']], 3600);
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::response(canaryPage())]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    expect(canaryRow()->outcome)->toBe(CanaryOutcome::BadUrl);
});

test('a block stores unreachable, counts the streak and keeps the baseline', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::sequence()
        ->push(canaryPage('20.00'))
        ->push('blocked', 403)
        ->push('blocked', 403)]);

    $this->artisan('dipcatch:canary')->assertSuccessful();
    $this->artisan('dipcatch:canary')->assertSuccessful();
    $this->artisan('dipcatch:canary')->assertSuccessful();

    $row = canaryRow();

    expect($row->outcome)->toBe(CanaryOutcome::Unreachable)
        ->and($row->consecutive_unreachable)->toBe(2)
        ->and((float) $row->last_ok_price)->toBe(20.0);
});

test('any outcome other than unreachable resets the streak', function (string $body, int $status, CanaryOutcome $expected): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::sequence()
        ->push('blocked', 403)
        ->push($body, $status)]);

    $this->artisan('dipcatch:canary')->assertSuccessful();
    $this->artisan('dipcatch:canary')->assertSuccessful();

    $row = canaryRow();

    expect($row->outcome)->toBe($expected)
        ->and($row->consecutive_unreachable)->toBe(0);
})->with([
    'rot' => ['<html><body>redesigned</body></html>', 200, CanaryOutcome::Rot],
    'bad url' => ['gone', 404, CanaryOutcome::BadUrl],
]);

test('canary traffic leaves the host failure memory alone', function (): void {
    DB::table('host_fetch_failures')->insert([
        'host' => 'bol.com',
        'kind' => 'blocked',
        'failures' => 3,
        'last_failed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::sequence()
        ->push(canaryPage('20.00'))
        ->push('blocked', 403)]);

    // A success would call HostFetchMemory::forget() and erase what a real
    // probe recorded; a block would invent a failure of its own.
    $this->artisan('dipcatch:canary')->assertSuccessful();
    expect(DB::table('host_fetch_failures')->where('host', 'bol.com')->count())->toBe(1);

    $this->artisan('dipcatch:canary')->assertSuccessful();
    expect(DB::table('host_fetch_failures')->where('host', 'bol.com')->value('failures'))->toEqual(3);
});

test('one failing entry does not abort the run', function (): void {
    config()->set('canary.adapters', [
        'bol' => 'not-a-url',
        'jumbo' => 'https://www.jumbo.com/producten/canary/',
    ]);
    Cache::put('dipcatch:robots:jumbo.com', [], 3600);
    Http::fake(['https://www.jumbo.com/producten/canary/' => Http::response(canaryPage('20.00'))]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    expect(AdapterCanaryResult::query()->count())->toBe(2);
});

test('an adapter removed from the list loses its row', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::response(canaryPage('20.00'))]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    config()->set('canary.adapters', ['jumbo' => 'https://www.jumbo.com/producten/canary/']);
    Cache::put('dipcatch:robots:jumbo.com', [], 3600);
    Http::fake(['https://www.jumbo.com/producten/canary/' => Http::response(canaryPage('20.00'))]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    expect(AdapterCanaryResult::query()->where('adapter', 'bol')->exists())->toBeFalse();
});

test('nothing is fetched outside the configured environments', function (): void {
    config()->set('canary.environments', ['production']);
    Http::fake();

    $this->artisan('dipcatch:canary')->assertSuccessful();

    Http::assertNothingSent();
    expect(AdapterCanaryResult::query()->count())->toBe(0);
});

test('a first ever run that is unreachable starts the streak at one', function (): void {
    Http::fake(['https://www.bol.com/nl/nl/p/canary/' => Http::response('blocked', 403)]);

    $this->artisan('dipcatch:canary')->assertSuccessful();

    $row = canaryRow();

    expect($row->outcome)->toBe(CanaryOutcome::Unreachable)
        ->and($row->consecutive_unreachable)->toBe(1)
        ->and($row->last_ok_price)->toBeNull();
});
