<?php declare(strict_types=1);

use App\Services\ShopFetcher\UrlSafetyGuard;
use App\Support\UrlNormalizer;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\RequestInterface;

beforeEach(function (): void {
    // These tests assert the prod behavior — temporarily disable the test-suite
    // bypass that allows loopback for Herd's `.test` hosts.
    config()->set('dipcatch.fetcher.allow_private_ips', false);
    config()->set('dipcatch.fetcher.allow_unresolved', false);
});

test('rejects loopback IP literals', function (): void {
    expect(fn () => new UrlSafetyGuard()->assertSafe('http://127.0.0.1/foo'))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects link-local AWS metadata IP', function (): void {
    expect(fn () => new UrlSafetyGuard()->assertSafe('http://169.254.169.254/latest/meta-data/'))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects private RFC1918 IPv4', function (): void {
    expect(fn () => new UrlSafetyGuard()->assertSafe('http://10.0.0.5/p'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new UrlSafetyGuard()->assertSafe('http://192.168.1.1/p'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new UrlSafetyGuard()->assertSafe('http://172.16.0.1/p'))->toThrow(InvalidArgumentException::class);
});

test('accepts a public host that resolves to a public IP', function (): void {
    // example.com is the canonical safe-public test fixture.
    expect(fn () => new UrlSafetyGuard()->assertSafe('https://example.com/p'))
        ->not->toThrow(InvalidArgumentException::class);
});

test('rejects unparseable URLs', function (): void {
    expect(fn () => new UrlSafetyGuard()->assertSafe('not a url'))
        ->toThrow(InvalidArgumentException::class);
});

test('the private-IP bypass is ignored when the app runs as production', function (): void {
    config()->set('dipcatch.fetcher.allow_private_ips', true);
    $this->app->detectEnvironment(fn (): string => 'production');

    // Pin the message: assertSafe() throws the same class for an unparseable
    // URL and for a host that will not resolve, so the class alone would let
    // this pass for the wrong reason.
    expect(fn () => new UrlSafetyGuard()->assertSafe('http://127.0.0.1/foo'))
        ->toThrow(InvalidArgumentException::class, 'URL resolves to a non-public address (127.0.0.1)');
});

test('the private-IP bypass still works outside production', function (): void {
    // This is what keeps local development against Herd working. If it breaks,
    // the production guard above has been applied too widely.
    config()->set('dipcatch.fetcher.allow_private_ips', true);

    expect(fn () => new UrlSafetyGuard()->assertSafe('http://127.0.0.1/foo'))
        ->not->toThrow(InvalidArgumentException::class);
});

test('the unresolved-host bypass is ignored when the app runs as production', function (): void {
    // The second escape hatch. A DNS miss failing open hands the fetcher a
    // destination nothing has vetted, so production refuses it too.
    // `.invalid` is reserved by RFC 2606 and never resolves.
    config()->set('dipcatch.fetcher.allow_unresolved', true);
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(fn () => new UrlSafetyGuard()->assertSafe('http://dipcatch-does-not-exist.invalid/p'))
        ->toThrow(InvalidArgumentException::class, 'Cannot resolve host: dipcatch-does-not-exist.invalid');
});

test('the unresolved-host bypass still works outside production', function (): void {
    config()->set('dipcatch.fetcher.allow_unresolved', true);

    expect(fn () => new UrlSafetyGuard()->assertSafe('http://dipcatch-does-not-exist.invalid/p'))
        ->not->toThrow(InvalidArgumentException::class);
});

test('a fully qualified host loses its DNS root dot, so host checks still match', function (): void {
    expect(UrlNormalizer::normalizeHost('www.plus.nl.'))->toBe('plus.nl')
        ->and(UrlNormalizer::normalizeHost('WWW.Vomar.NL.'))->toBe('vomar.nl');
});

test('refuses the internal ranges PHP counts as public', function (string $url): void {
    config()->set('dipcatch.fetcher.allow_private_ips', false);

    expect(fn () => new UrlSafetyGuard()->assertSafe($url))
        ->toThrow(InvalidArgumentException::class, 'non-public address');
})->with([
    'carrier-grade NAT' => ['http://100.64.0.1/'],
    'benchmark range' => ['http://198.18.0.1/'],
    'NAT64 of loopback' => ['http://[64:ff9b::7f00:1]/'],
    'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
]);

test('still lets a public address through', function (): void {
    config()->set('dipcatch.fetcher.allow_private_ips', false);

    expect(fn () => new UrlSafetyGuard()->assertSafe('http://93.184.216.34/'))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => new UrlSafetyGuard()->assertSafe('http://[2606:2800:220:1:248:1893:25c8:1946]/'))->not->toThrow(InvalidArgumentException::class);
});

test('makes curl connect to the address it checked, so a second DNS answer cannot redirect it', function (): void {
    config()->set('dipcatch.fetcher.allow_private_ips', false);
    Cache::put('dipcatch:dns:rebind.example', ['93.184.216.34'], 300);

    $seen = [];
    $handler = function (RequestInterface $request, array $options) use (&$seen): string {
        $seen = $options;

        return 'sent';
    };

    new UrlSafetyGuard()->middleware()($handler)(new Request('GET', 'https://rebind.example/p'), []);

    // A proxy from the environment would resolve the host itself, so none is used.
    expect($seen['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['rebind.example:443:93.184.216.34'])
        ->and($seen['proxy'] ?? null)->toBe(['no' => ['*']]);
});

test('refuses a request whose host resolves inside the network, before anything is sent', function (): void {
    config()->set('dipcatch.fetcher.allow_private_ips', false);
    Cache::put('dipcatch:dns:inside.example', ['10.0.0.8'], 300);

    $sent = false;
    $handler = function () use (&$sent): string {
        $sent = true;

        return 'sent';
    };

    expect(fn () => new UrlSafetyGuard()->middleware()($handler)(new Request('GET', 'http://inside.example/robots.txt'), []))
        ->toThrow(InvalidArgumentException::class)
        ->and($sent)->toBeFalse();
});
