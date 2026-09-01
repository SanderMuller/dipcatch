<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\HostUrl;

test('a host matches itself and its subdomains, in any spelling', function (string $url, bool $matches): void {
    expect(HostUrl::matches($url, 'dirk.nl'))->toBe($matches);
})->with([
    ['https://www.dirk.nl/p/1', true],
    ['https://DIRK.NL/p/1', true],
    ['https://www.dirk.nl./p/1', true],
    ['https://shop.dirk.nl/p/1', true],
    // The dot boundary is what stops a lookalike domain from matching.
    ['https://notdirk.nl/p/1', false],
    ['https://dirk.nl.evil.test/p/1', false],
    ['not a url', false],
]);

test('the product id is the last numeric segment, or nothing', function (string $url, ?string $id): void {
    expect(HostUrl::lastNumericSegment($url))->toBe($id);
})->with([
    ['https://www.dirk.nl/boodschappen/x/x/x/115212', '115212'],
    ['https://www.dirk.nl/boodschappen/x/x/x/115212/', '115212'],
    ['https://www.dirk.nl/boodschappen/x/x/x/115212?utm_source=x', '115212'],
    ['https://www.dirk.nl/boodschappen/categorie', null],
    ['https://www.dirk.nl/', null],
]);

test('a prefixed id gives up its digits', function (): void {
    expect(HostUrl::lastSegmentDigits('https://www.lidl.nl/p/lay-s/p10033095', 'p'))->toBe('10033095')
        ->and(HostUrl::lastSegmentDigits('https://www.lidl.nl/p/lay-s', 'p'))->toBeNull();
});
