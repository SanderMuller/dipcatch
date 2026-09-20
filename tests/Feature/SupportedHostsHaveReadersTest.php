<?php declare(strict_types=1);

use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\UserSelectorAdapter;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Support\SupportedShops;
use Illuminate\Support\Facades\Config;

/**
 * @return list<string>
 */
function siteHosts(string $key): array
{
    $hosts = [];

    foreach (Config::array($key) as $host) {
        if (is_string($host)) {
            $hosts[] = $host;
        }
    }

    return $hosts;
}

/**
 * Every shop the marketing site names gets a landing page claiming "DipCatch
 * has a reader written for this shop" (`ShopPages::facts()`). That claim is
 * only true while the host in `site.supported_hosts` is one a dedicated
 * reader answers to.
 *
 * It was not: the site claimed `poiesz.nl`, a domain the chain does not use,
 * while `PoieszAdapter` matches `poiesz-supermarkten.nl`. A visitor who
 * pasted the marketed address got the generic chain.
 */
function readsHost(string $host): bool
{
    if (app(AhApiSource::class)->supports($host) || app(CheckjebonSource::class)->supports($host)) {
        return true;
    }

    // A host adapter returns `skip` only when the host is not its own, so a
    // non-skip verdict on an empty page proves it claims the host — whether
    // it could read that particular page or not.
    foreach (Config::array('dipcatch.adapters') as $class) {
        if (! is_string($class) || ! is_subclass_of($class, HostSpecificAdapter::class)) {
            continue;
        }

        if ($class === UserSelectorAdapter::class) {
            continue;
        }

        $adapter = app($class);

        if (! $adapter instanceof ShopAdapter) {
            continue;
        }

        if (! $adapter->extract("https://{$host}/p/1", '<html><body></body></html>')->isSkip()) {
            return true;
        }
    }

    return false;
}

test('every marketed shop host has a reader written for it', function (string $host): void {
    expect(readsHost($host))->toBeTrue();
    // The dataset is resolved before the application boots, so it reads the
    // config file rather than the container.
})->with(function (): array {
    /** @var array<string, mixed> $site */
    $site = require __DIR__ . '/../../config/site.php';

    /** @var list<string> $hosts */
    $hosts = $site['supported_hosts'];

    return array_combine($hosts, $hosts);
});

test('every homepage chip is a host the site also supports', function (): void {
    expect(array_diff(siteHosts('site.homepage_hosts'), siteHosts('site.supported_hosts')))->toBeEmpty();
});

test('every use-case host is a host the site also supports', function (): void {
    $named = [];

    foreach (Config::array('site.use_cases') as $hosts) {
        foreach ((array) $hosts as $host) {
            if (is_string($host)) {
                $named[] = $host;
            }
        }
    }

    expect(array_diff(array_unique($named), siteHosts('site.supported_hosts')))->toBeEmpty();
});

test('every marketed host has a display name', function (): void {
    // A host with no entry falls back to showing the bare domain, which is
    // how a wrong host slips past a reader: it still renders.
    $named = [];

    foreach (array_keys(Config::array('site.shop_names')) as $host) {
        if (is_string($host)) {
            $named[] = $host;
        }
    }

    expect(array_diff(siteHosts('site.supported_hosts'), $named))->toBeEmpty();
});

test('no two marketed hosts claim the same landing page', function (): void {
    // The slug swaps dots for hyphens, so `a.b.nl` and `a-b.nl` would both
    // want `/shops/a-b-nl`. `ShopPages::find()` takes the first and the
    // second shop silently loses its page.
    $slugs = array_column(SupportedShops::rows(), 'slug');

    expect(array_unique($slugs))->toHaveCount(count($slugs));
});
