<?php declare(strict_types=1);

use App\Services\ShopFetcher\UrlSafetyGuard;
use App\Support\UrlNormalizer;

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
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new UrlSafetyGuard()->assertSafe('http://192.168.1.1/p'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new UrlSafetyGuard()->assertSafe('http://172.16.0.1/p'))
        ->toThrow(InvalidArgumentException::class);
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
