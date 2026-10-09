<?php declare(strict_types=1);

use App\Support\ImageUrl;

test('safe keeps http and https urls', function (string $url): void {
    expect(ImageUrl::safe($url))->toBe($url);
})->with([
    'https://shop.test/a.jpg',
    'http://shop.test/a.jpg',
    'HTTPS://shop.test/a.jpg',
    'HtTp://shop.test/a.jpg',
]);

test('safe rejects anything that is not http(s)', function (mixed $url): void {
    expect(ImageUrl::safe($url))->toBeNull();
})->with([
    'javascript:alert(1)',
    'data:image/png;base64,AAAA',
    '/relative.jpg',
    '',
    null,
    42,
]);

test('absolute resolves a relative image against the page url', function (string $image, string $expected): void {
    expect(ImageUrl::absolute($image, 'https://shop.test/p/detail/1?x=2'))->toBe($expected);
})->with([
    ['/img/a.jpg', 'https://shop.test/img/a.jpg'],
    ['a.jpg', 'https://shop.test/p/detail/a.jpg'],
    ['//cdn.shop.test/a.jpg', 'https://cdn.shop.test/a.jpg'],
    ['https://cdn.shop.test/a.jpg', 'https://cdn.shop.test/a.jpg'],
]);

test('absolute keeps the port of the page url', function (): void {
    expect(ImageUrl::absolute('/a.jpg', 'https://shop.test:8443/p/1'))
        ->toBe('https://shop.test:8443/a.jpg');
});

test('absolute rejects an unsafe scheme and an unusable base', function (): void {
    expect(ImageUrl::absolute('javascript:alert(1)', 'https://shop.test/p/1'))->toBeNull()
        ->and(ImageUrl::absolute('/a.jpg', ''))->toBeNull()
        ->and(ImageUrl::absolute(url: null, baseUrl: 'https://shop.test/p/1'))->toBeNull();
});

test('absolute canonicalizes dot segments', function (string $image, string $expected): void {
    expect(ImageUrl::absolute($image, 'https://shop.test/p/detail/1'))->toBe($expected);
})->with([
    'parent' => ['../img/a.jpg', 'https://shop.test/p/img/a.jpg'],
    'current' => ['./a.jpg', 'https://shop.test/p/detail/a.jpg'],
]);

test('absolute resolves a query-only or fragment-only reference against the page itself', function (): void {
    expect(ImageUrl::absolute('?size=large', 'https://shop.test/p/detail/1?x=2'))
        ->toBe('https://shop.test/p/detail/1?size=large')
        ->and(ImageUrl::absolute('#hero', 'https://shop.test/p/detail/1?x=2'))
        ->toBe('https://shop.test/p/detail/1?x=2#hero');
});

test('a blank image reference is no image, not the page it came from', function (mixed $image): void {
    // RFC 3986 resolves an empty reference to the base URI, so dropping this
    // guard would store the product page as the product photo.
    expect(ImageUrl::absolute($image, 'https://shop.test/p/detail/1'))->toBeNull();
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
]);

test('credentials in the page url stay out of the image url', function (string $image, string $expected): void {
    expect(ImageUrl::absolute($image, 'https://user:pw@shop.test/p/detail/1'))->toBe($expected);
})->with([
    'root relative' => ['/a.jpg', 'https://shop.test/a.jpg'],
    'directory relative' => ['a.jpg', 'https://shop.test/p/detail/a.jpg'],
    'query only' => ['?v=2', 'https://shop.test/p/detail/1?v=2'],
]);

test('an at sign in the path is not credentials', function (): void {
    expect(ImageUrl::absolute('a.jpg', 'https://shop.test/p/@brand/1'))
        ->toBe('https://shop.test/p/@brand/a.jpg');
});

test('a colon in the first path segment reads as a scheme, so the image is dropped', function (): void {
    // RFC 3986 section 4.2: such a reference has to be written `./a.jpg:1`.
    // The hand-rolled resolver was lenient here; the standard one is not.
    expect(ImageUrl::absolute('a.jpg:1', 'https://shop.test/p/detail/1'))->toBeNull()
        ->and(ImageUrl::absolute('./a.jpg:1', 'https://shop.test/p/detail/1'))
        ->toBe('https://shop.test/p/detail/a.jpg:1');
});

test('an at sign outside the authority is not credentials', function (string $base, string $expected): void {
    expect(ImageUrl::absolute('/a.jpg', $base))->toBe($expected);
})->with([
    'in the query of a pathless url' => ['https://shop.test?email=a@b.test', 'https://shop.test/a.jpg'],
    'in the fragment of a pathless url' => ['https://shop.test#a@b.test', 'https://shop.test/a.jpg'],
    'in the path' => ['https://shop.test/p/@brand/1', 'https://shop.test/a.jpg'],
    'in the query after a path' => ['https://shop.test/p/1?to=a@b.test', 'https://shop.test/a.jpg'],
    'credentials and an at sign in the query' => ['https://u:pw@shop.test/p/1?to=a@b.test', 'https://shop.test/a.jpg'],
]);

test('thumbnail asks a resizing image server for a small copy, at a size it accepts', function (string $url, string $expected): void {
    expect(ImageUrl::thumbnail($url))->toBe($expected);
})->with([
    'AH' => ['https://static.ah.nl/dam/product/AHI_1?revLabel=1&rendition=800x800_WEBP&fileType=binary', 'https://static.ah.nl/dam/product/AHI_1?revLabel=1&rendition=200x200_WEBP&fileType=binary'],
    'Dirk' => ['https://web-fileserver.dirk.nl/artikelen/1.png?width=500&height=500&mode=crop', 'https://web-fileserver.dirk.nl/artikelen/1.png?width=160&height=160&mode=crop'],
    'a Shopify shop' => ['https://www.bodyandfit.com/cdn/shop/files/1.png?v=17&width=1920', 'https://www.bodyandfit.com/cdn/shop/files/1.png?v=17&width=160'],
    'the Shopify CDN' => ['https://cdn.shopify.com/s/files/1/p.jpg?v=1&width=800&height=800&crop=center', 'https://cdn.shopify.com/s/files/1/p.jpg?v=1&width=160'],
    'Amazon' => ['https://m.media-amazon.com/images/I/51np1PVT2+L.jpg', 'https://m.media-amazon.com/images/I/51np1PVT2+L._SL160_.jpg'],
    'Amazon with a size' => ['https://m.media-amazon.com/images/I/51np1PVT2+L._AC_SL1500_.jpg', 'https://m.media-amazon.com/images/I/51np1PVT2+L._SL160_.jpg'],
]);

test('thumbnail leaves a server that refuses other sizes, or does not resize, alone', function (string $url): void {
    expect(ImageUrl::thumbnail($url))->toBe($url);
})->with([
    'Jumbo' => 'https://www.jumbo.com/dam-images/fit-in/360x360/Products/1.png',
    'Zooplus' => 'https://media.zooplus.com/bilder/8/400/1.jpg',
    'Spar' => 'https://media.spar.nl/productdetail/fanta-1.5-Liter.jpg',
    'AH without a rendition' => 'https://static.ah.nl/dam/product/AHI_1',
]);
