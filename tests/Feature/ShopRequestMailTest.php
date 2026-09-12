<?php declare(strict_types=1);

use App\Support\ShopRequestMail;

test('no mailto is built when no contact address is configured', function (): void {
    config()->set('site.contact_email');

    expect(ShopRequestMail::href('etos.nl', 'https://www.etos.nl/p/1'))->toBeNull();
});

test('an empty or blank contact address is treated as missing', function (): void {
    config()->set('site.contact_email', '');
    expect(ShopRequestMail::href('etos.nl', 'https://www.etos.nl/p/1'))->toBeNull();

    config()->set('site.contact_email', '  ');
    expect(ShopRequestMail::href('etos.nl', 'https://www.etos.nl/p/1'))->toBeNull();
});

test('the mailto names the shop and carries the product URL', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    $href = ShopRequestMail::href('etos.nl', 'https://www.etos.nl/p/1');
    $decoded = rawurldecode((string) $href);

    expect($href)->toStartWith('mailto:hello@example.test?')
        ->and($decoded)->toContain('Request etos.nl on DipCatch')
        ->and($decoded)->toContain('https://www.etos.nl/p/1')
        ->and($decoded)->toContain('Shop: etos.nl');
});

test('a product URL supplies the shop host when none is passed', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    $decoded = rawurldecode((string) ShopRequestMail::href(null, 'https://www.petsplace.nl/p/9'));

    expect($decoded)->toContain('Request www.petsplace.nl on DipCatch');
});

test('a javascript URL and a junk host are dropped from the mailto', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    $decoded = rawurldecode((string) ShopRequestMail::href("etos.nl\nBcc:evil@x.test", 'javascript:alert(1)'));

    expect($decoded)->toContain('Request a shop on DipCatch')
        ->and($decoded)->not->toContain('etos.nl')
        ->and($decoded)->not->toContain('javascript:');
});
