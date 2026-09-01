<?php declare(strict_types=1);

use App\Support\ImageUrl;

test('safe keeps http and https urls', function (string $url): void {
    expect(ImageUrl::safe($url))->toBe($url);
})->with([
    'https://shop.test/a.jpg',
    'http://shop.test/a.jpg',
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
        ->and(ImageUrl::absolute(null, 'https://shop.test/p/1'))->toBeNull();
});
