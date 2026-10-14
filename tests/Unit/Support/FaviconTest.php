<?php declare(strict_types=1);

use App\Support\Favicon;

test('url points at the Google favicon proxy for the host', function (): void {
    expect(Favicon::url('www.ah.nl'))
        ->toBe('https://www.google.com/s2/favicons?domain=www.ah.nl&sz=64');
});

test('url encodes unusual host characters', function (): void {
    expect(Favicon::url('a&b.test'))
        ->toBe('https://www.google.com/s2/favicons?domain=a%26b.test&sz=64');
});

test('html escapes the host in both the image url and the text', function (): void {
    $html = Favicon::html('evil"><script>.test');

    expect($html)
        ->not->toContain('<script>')
        ->toContain('evil&quot;&gt;&lt;script&gt;.test');
});
