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

test('html shows a label in place of the host and keeps the host as the tooltip', function (): void {
    $html = Favicon::html('webwinkel.poiesz-supermarkten.nl', 'Poiesz & Co');

    expect($html)
        ->toContain('title="webwinkel.poiesz-supermarkten.nl"')
        ->toContain('>Poiesz &amp; Co</span>')
        ->not->toContain('>webwinkel.poiesz-supermarkten.nl</span>');
});
