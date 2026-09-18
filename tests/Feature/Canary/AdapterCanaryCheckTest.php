<?php declare(strict_types=1);

use App\Enums\CanaryOutcome;
use App\Health\AdapterCanaryCheck;
use App\Models\AdapterCanaryResult;
use App\PriceAdapters\GenericAdapter;
use App\PriceAdapters\Hosts\BolAdapter;
use App\PriceAdapters\Hosts\JumboAdapter;
use App\PriceAdapters\Hosts\ZooplusAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\UserSelectorAdapter;
use App\Support\CanaryEntries;
use Spatie\Health\Enums\Status;

/**
 * Everything that needs a person must be a failure: `only_on_failure` drops
 * warnings before notifying, so a warning is seen only by someone already
 * looking at the health page.
 */
beforeEach(function (): void {
    config()->set('canary.adapters', [
        'bol' => 'https://www.bol.com/nl/nl/p/canary/',
        'jumbo' => 'https://www.jumbo.com/producten/canary/',
    ]);
    config()->set('canary.stale_after_hours', 36);
    config()->set('canary.unreachable_fail_runs', 5);

    // The coverage arm compares against the registered host adapters; these
    // tests pin the canary behaviour, not the adapter roster.
    config()->set('dipcatch.adapters', [
        BolAdapter::class,
        JumboAdapter::class,
        JsonLdAdapter::class,
        GenericAdapter::class,
    ]);
});

/** @param array<string, mixed> $overrides */
function canaryResult(string $adapter, CanaryOutcome $outcome, array $overrides = []): AdapterCanaryResult
{
    /** @var array<string, mixed> $attributes */
    $attributes = $overrides + [
        'adapter' => $adapter,
        'url' => 'https://example.test/p/1',
        'outcome' => $outcome,
        'observed_adapter' => $adapter,
        'price' => '20.00',
        'last_ok_price' => '20.00',
        'consecutive_unreachable' => 0,
        'detail' => null,
        'checked_at' => now(),
    ];

    return AdapterCanaryResult::query()->create($attributes);
}

function bothFresh(CanaryOutcome $outcome = CanaryOutcome::Ok): void
{
    canaryResult('bol', $outcome);
    canaryResult('jumbo', CanaryOutcome::Ok);
}

test('every adapter reading its page is ok', function (): void {
    bothFresh();

    expect(new AdapterCanaryCheck()->run()->status)->toEqual(Status::ok());
});

test('rot fails and names the adapter', function (): void {
    bothFresh(CanaryOutcome::Rot);

    $result = new AdapterCanaryCheck()->run();

    expect($result->status)->toEqual(Status::failed())
        ->and($result->notificationMessage)->toContain('bol');
});

test('a bad url fails rather than warns', function (): void {
    bothFresh(CanaryOutcome::BadUrl);

    expect(new AdapterCanaryCheck()->run()->status)->toEqual(Status::failed());
});

test('a single unreachable run stays ok', function (): void {
    canaryResult('bol', CanaryOutcome::Unreachable, ['consecutive_unreachable' => 1]);
    canaryResult('jumbo', CanaryOutcome::Ok);

    expect(new AdapterCanaryCheck()->run()->status)->toEqual(Status::ok());
});

test('two consecutive unreachable runs warn', function (): void {
    canaryResult('bol', CanaryOutcome::Unreachable, ['consecutive_unreachable' => 2]);
    canaryResult('jumbo', CanaryOutcome::Ok);

    expect(new AdapterCanaryCheck()->run()->status)->toEqual(Status::warning());
});

test('a canary blind for the escalation count fails', function (): void {
    canaryResult('bol', CanaryOutcome::Unreachable, ['consecutive_unreachable' => 5]);
    canaryResult('jumbo', CanaryOutcome::Ok);

    expect(new AdapterCanaryCheck()->run()->status)->toEqual(Status::failed());
});

test('an empty table fails naming every configured adapter', function (): void {
    $result = new AdapterCanaryCheck()->run();

    expect($result->status)->toEqual(Status::failed())
        ->and($result->notificationMessage)->toContain('bol')
        ->and($result->notificationMessage)->toContain('jumbo');
});

test('one fresh row does not mask a stale one', function (): void {
    canaryResult('bol', CanaryOutcome::Ok, ['checked_at' => now()->subHours(72)]);
    canaryResult('jumbo', CanaryOutcome::Ok);

    $result = new AdapterCanaryCheck()->run();

    expect($result->status)->toEqual(Status::failed())
        ->and($result->notificationMessage)->toContain('stale');
});

test('a configured adapter with no row fails', function (): void {
    canaryResult('bol', CanaryOutcome::Ok);

    $result = new AdapterCanaryCheck()->run();

    expect($result->status)->toEqual(Status::failed())
        ->and($result->notificationMessage)->toContain('jumbo');
});

test('a registered host adapter with no canary entry fails', function (): void {
    bothFresh();

    config()->set('dipcatch.adapters', [
        BolAdapter::class,
        JumboAdapter::class,
        ZooplusAdapter::class,
        JsonLdAdapter::class,
    ]);

    $result = new AdapterCanaryCheck()->run();

    expect($result->status)->toEqual(Status::failed())
        ->and($result->notificationMessage)->toContain('zooplus');
});

test('the generic adapters are not expected to have canary entries', function (): void {
    bothFresh();

    // JsonLd and Generic are in the chain above and read no particular shop.
    expect(new AdapterCanaryCheck()->run()->status)->toEqual(Status::ok());
});

test('every registered host adapter has a canary entry in the shipped config', function (): void {
    // The URLs are environment-backed and empty outside production, so this
    // reads the config file's keys rather than CanaryEntries::configured().
    // A new host adapter must arrive with an entry, or the check fails in
    // production the day it ships.
    /** @var array<string, mixed> $shipped */
    $shipped = require base_path('config/canary.php');

    /** @var array<string, string|null> $entries */
    $entries = $shipped['adapters'];

    expect(array_diff(CanaryEntries::hostAdapterKeys(), array_keys($entries)))->toBeEmpty();
});

test('the user selector adapter is never expected to have a canary entry', function (): void {
    config()->set('dipcatch.adapters', [
        UserSelectorAdapter::class,
        BolAdapter::class,
        JumboAdapter::class,
    ]);

    // It implements HostSpecificAdapter like a host adapter, but it reads
    // whatever selector a user typed, on any host. Expecting a canary for it
    // would fail the check on every run in production.
    expect(CanaryEntries::hostAdapterKeys())->not->toContain('user-selector');

    bothFresh();

    expect(new AdapterCanaryCheck()->run()->status)->toEqual(Status::ok());
});
