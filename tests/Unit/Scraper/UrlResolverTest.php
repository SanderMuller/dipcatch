<?php declare(strict_types=1);

use App\Services\Scraper\UrlResolver;

test('UrlResolver passes through absolute URLs', function (): void {
    expect(UrlResolver::resolve('https://example.com/products/abc', 'https://cdn.example.com/x.png'))
        ->toBe('https://cdn.example.com/x.png');
});

test('UrlResolver expands protocol-relative URLs using the base scheme', function (): void {
    expect(UrlResolver::resolve('https://example.com/p/abc', '//cdn.example.com/x.png'))
        ->toBe('https://cdn.example.com/x.png');
});

test('UrlResolver resolves an absolute path against the base origin', function (): void {
    expect(UrlResolver::resolve('https://example.com/p/abc', '/images/x.png'))
        ->toBe('https://example.com/images/x.png');
});

test('UrlResolver resolves a relative path against the base directory', function (): void {
    expect(UrlResolver::resolve('https://example.com/p/abc', 'thumbs/x.png'))
        ->toBe('https://example.com/p/thumbs/x.png');
});

test('UrlResolver leaves data: URLs alone', function (): void {
    expect(UrlResolver::resolve('https://example.com/', 'data:image/png;base64,aGVsbG8='))
        ->toBe('data:image/png;base64,aGVsbG8=');
});
