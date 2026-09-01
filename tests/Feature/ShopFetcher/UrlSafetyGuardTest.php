<?php declare(strict_types=1);

use App\Services\ShopFetcher\UrlSafetyGuard;

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
