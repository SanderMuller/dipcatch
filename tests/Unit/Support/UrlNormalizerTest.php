<?php declare(strict_types=1);

use App\Support\UrlNormalizer;

test('keeps the required trailing slash on SPAR product URLs', function (): void {
    // SPAR answers 404 without it.
    $url = 'https://www.spar.nl/fanta-fanta-cassis-pet-1.5l-9256413/';

    expect(UrlNormalizer::normalize($url))->toBe($url)
        ->and(UrlNormalizer::hash(UrlNormalizer::normalize($url)))
        ->toBe(UrlNormalizer::hash(rtrim($url, '/')));
});

test('keeps the trailing slash a Shopware shop canonicalises to', function (): void {
    // Measured on production 2026-09-22. Without the slash dierapotheker.nl
    // answers 301 to the slash form, and following that redirect is a second
    // request a millisecond after the first. That host rate limits on exactly
    // that shape, so every probe was refused — nine in a row — while a direct
    // request to the same page succeeded five times out of five.
    $url = 'https://www.dierapotheker.nl/vet-concept-sana-paard-kattenvoer/9271/';

    expect(UrlNormalizer::normalize($url))->toBe($url);
});

test('lowercases scheme and host', function (): void {
    expect(UrlNormalizer::normalize('HTTPS://ExAmple.COM/foo'))
        ->toBe('https://example.com/foo');
});

test('keeps www. in the URL — not every shop answers on its apex host', function (): void {
    expect(UrlNormalizer::normalize('https://www.example.com/foo'))
        ->toBe('https://www.example.com/foo');
});

test('www and apex forms still dedupe to one shop', function (): void {
    $withWww = UrlNormalizer::hash(UrlNormalizer::normalize('https://www.example.com/foo'));
    $apex = UrlNormalizer::hash(UrlNormalizer::normalize('https://example.com/foo'));

    expect($withWww)->toBe($apex);
});

test('the comparison host drops www., the URL host does not', function (): void {
    expect(UrlNormalizer::normalizeHost('www.example.com'))->toBe('example.com')
        ->and(UrlNormalizer::canonicalHost('www.example.com'))->toBe('www.example.com');
});

test('strips userinfo from authority', function (): void {
    expect(UrlNormalizer::normalize('https://user:pass@example.com/foo'))
        ->toBe('https://example.com/foo');
});

test('strips default ports', function (): void {
    expect(UrlNormalizer::normalize('http://example.com:80/foo'))
        ->toBe('http://example.com/foo')
        ->and(UrlNormalizer::normalize('https://example.com:443/foo'))->toBe('https://example.com/foo');
});

test('keeps non-default ports', function (): void {
    expect(UrlNormalizer::normalize('https://example.com:8443/foo'))
        ->toBe('https://example.com:8443/foo');
});

test('empty path normalizes to /', function (): void {
    expect(UrlNormalizer::normalize('https://example.com'))
        ->toBe('https://example.com/');
});

test('keeps the path as the shop wrote it', function (): void {
    // A stripped slash is a redirect waiting to happen on every CMS that
    // canonicalises with one, and a redirect is a second request.
    expect(UrlNormalizer::normalize('https://example.com/foo/bar/'))
        ->toBe('https://example.com/foo/bar/')
        ->and(UrlNormalizer::normalize('https://example.com/foo/bar'))
        ->toBe('https://example.com/foo/bar');
});

test('both slash forms are still one shop', function (): void {
    // Identity is the resource, not the spelling — and this is what keeps
    // every hash written while the slash was being stripped still matching.
    expect(UrlNormalizer::hash(UrlNormalizer::normalize('https://example.com/foo/bar/')))
        ->toBe(UrlNormalizer::hash(UrlNormalizer::normalize('https://example.com/foo/bar')));
});

test('preserves path case', function (): void {
    expect(UrlNormalizer::normalize('https://example.com/Foo-Bar'))
        ->toBe('https://example.com/Foo-Bar');
});

test('canonicalizes percent-encoded path', function (): void {
    expect(UrlNormalizer::normalize('https://example.com/foo%2Dbar'))
        ->toBe('https://example.com/foo-bar');
});

test('strips fragment', function (): void {
    expect(UrlNormalizer::normalize('https://example.com/foo#section'))
        ->toBe('https://example.com/foo');
});

test('sorts query params alphabetically by key', function (): void {
    expect(UrlNormalizer::normalize('https://example.com/?b=2&a=1'))
        ->toBe('https://example.com/?a=1&b=2');
});

test('sorts repeated keys by value, preserving duplicates', function (): void {
    expect(UrlNormalizer::normalize('https://example.com/?a=2&a=1'))
        ->toBe('https://example.com/?a=1&a=2');
});

test('drops tracking parameters', function (string $param): void {
    expect(UrlNormalizer::normalize("https://example.com/foo?keep=1&{$param}=x"))
        ->toBe('https://example.com/foo?keep=1');
})->with([
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'gclid',
    'fbclid',
    'mc_eid',
    'mc_cid',
    'ref',
    'ref_src',
    '_ga',
]);

test('idn host is converted to ASCII punycode', function (): void {
    // The German umlaut domain "münchen.de" becomes "xn--mnchen-3ya.de".
    expect(UrlNormalizer::normalize('https://münchen.de/foo'))
        ->toBe('https://xn--mnchen-3ya.de/foo');
});

test('different query orders produce identical hashes', function (): void {
    $a = UrlNormalizer::normalize('https://example.com/p?a=1&b=2');
    $b = UrlNormalizer::normalize('https://example.com/p?b=2&a=1');

    expect($a)->toBe($b)
        ->and(UrlNormalizer::hash($a))->toBe(UrlNormalizer::hash($b));
});

test('rejects non-http(s) schemes', function (): void {
    expect(fn (): string => UrlNormalizer::normalize('ftp://example.com/'))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects unparseable URLs', function (): void {
    expect(fn (): string => UrlNormalizer::normalize('not a url'))
        ->toThrow(InvalidArgumentException::class);
});

test('normalizeHost is standalone helper', function (): void {
    expect(UrlNormalizer::normalizeHost('WWW.Example.COM'))
        ->toBe('example.com');
});

test('hash returns 64-char sha256 hex', function (): void {
    $hash = UrlNormalizer::hash('https://example.com/');

    expect($hash)
        ->toHaveLength(64)
        ->toMatch('/^[a-f0-9]{64}$/');
});

test('strips the click and campaign ids shops and ad networks add', function (string $param): void {
    expect(UrlNormalizer::normalize("https://shop.test/p/1?{$param}=abc123&size=xl"))->toBe('https://shop.test/p/1?size=xl');
})->with(['srsltid', 'gbraid', 'wbraid', 'gad_source', 'gad_campaignid', '_gl', 'msclkid', 'igshid', 'ttclid', 'awc', 'cjevent', 'irclickid', '_hsenc', 'mkt_tok']);

test('strips a tracking parameter whatever its case', function (): void {
    expect(UrlNormalizer::normalize('https://shop.test/p/1?UTM_Source=mail&SrsltId=x'))->toBe('https://shop.test/p/1');
});

test('keeps the parameters that choose what is sold', function (string $query): void {
    expect(UrlNormalizer::normalize("https://shop.test/p/1?{$query}"))->toBe("https://shop.test/p/1?{$query}");
})->with(['variant=123', 'sku=MP4838', 'activeVariant=169589.19', 'th=1', 'psc=1']);
