<?php declare(strict_types=1);

use App\Support\ProductTitle;

test('the shop name after a separator comes off', function (string $title, string $host, string $expected): void {
    expect(ProductTitle::clean($title, $host))->toBe($expected);
})->with([
    'spelled-out ampersand in the host' => [
        'Barebells Protein Bar Creamy Crisp - 12 x 55 g | Body & Shape Store',
        'bodyandshapestore.nl',
        'Barebells Protein Bar Creamy Crisp - 12 x 55 g',
    ],
    // Two letters, so only an exact match on the host label accepts it.
    'a two-letter chain' => ['Pindakaas 350 g - AH', 'www.ah.nl', 'Pindakaas 350 g'],
    'an en dash' => ['Melk halfvol 1 L – Jumbo', 'jumbo.com', 'Melk halfvol 1 L'],
    // Every label but the suffix counts, so a shop on a subdomain still
    // loses its own name.
    'a name behind a subdomain' => ['Melk 1 L | Jumbo', 'webshop.jumbo.com', 'Melk 1 L'],
]);

test('the Shopify placeholder variant comes off', function (): void {
    expect(ProductTitle::clean('Mars Hi-Protein Bar (12x59g) - Default Title', 'bodylab.nl'))
        ->toBe('Mars Hi-Protein Bar (12x59g)');
});

test('a Dutch buying word comes off', function (string $title, string $expected): void {
    expect(ProductTitle::clean($title, 'bodyandshapestore.nl'))->toBe($expected);
})->with([
    'kopen' => ['Barebells Protein Bar Cookies & Cream - 12 x 55 g kopen', 'Barebells Protein Bar Cookies & Cream - 12 x 55 g'],
    'bestellen' => ['Barebells Hazelnut Nougat 12 x 55 g bestellen', 'Barebells Hazelnut Nougat 12 x 55 g'],
    'goedkoop, behind a comma' => ['Mars Hi-Protein 12 x 59 g, goedkoop', 'Mars Hi-Protein 12 x 59 g'],
    'online in front of one' => ['Barebells Creamy Crisp online kopen', 'Barebells Creamy Crisp'],
]);

test('"online" on its own is a word products really end in', function (string $title): void {
    expect(ProductTitle::clean($title, 'bol.com'))->toBe($title);
})->with([
    'Nintendo Switch Online',
    'Xbox Game Pass Core Online',
]);

test('one pass exposes the next tail', function (): void {
    // The shop name hid the buying word in front of it.
    expect(ProductTitle::clean('Sanimed Skin Sensitive Kat online bestellen | Zooplus', 'zooplus.nl'))
        ->toBe('Sanimed Skin Sensitive Kat');
});

test('a title the shop wrote cleanly is left alone', function (string $title, string $host): void {
    expect(ProductTitle::clean($title, $host))->toBe($title);
})->with([
    // A hyphen inside the name says something about the product.
    'a hyphenated brand' => ['Coca-Cola Zero 1,5 L', 'ah.nl'],
    'a pack size after a dash' => ['Barebells Protein Bar Cookies & Cream - 12 x 55 g', 'bodyandshapestore.nl'],
    'a shop that names itself in the product' => ['Barebells Cookies & Cream', 'barebells.nl'],
    // The host contains these words, but the tail is not what the shop is
    // called, so a substring match would eat part of the product's name.
    'a word the host merely contains' => ['Melk - Bio', 'biomarkt.nl'],
    // The same, with the hyphen Dutch shop hosts commonly carry: a label is
    // matched whole, never in pieces.
    'a word that is one hyphenated part of the host' => ['Melk - Bio', 'bio-markt.nl'],
    'another hyphenated host' => ['Pure Nature Amandelen - Nature', 'pure-nature.nl'],
    // "Kat" is three letters and appears in the host, but it is the product.
    'no separator to cut on' => ['Sanimed Skin Sensitive Kat', 'katvoer.nl'],
]);

test('cleaning a cleaned title changes nothing', function (): void {
    $once = ProductTitle::clean('Barebells Protein Bar Creamy Crisp - 12 x 55 g | Body & Shape Store kopen', 'bodyandshapestore.nl');

    expect($once)->toBe('Barebells Protein Bar Creamy Crisp - 12 x 55 g')
        ->and(ProductTitle::clean($once, 'bodyandshapestore.nl'))->toBe($once);
});

test('a title that is nothing but junk keeps what the page said', function (): void {
    // Better a placeholder the user can see and override than a blank name.
    expect(ProductTitle::clean('Default Title', 'bodylab.nl'))->toBe('Default Title')
        ->and(ProductTitle::clean('Zooplus', 'zooplus.nl'))->toBe('Zooplus');
});

test('a missing title stays missing, and an unknown host disables only the shop rule', function (): void {
    expect(ProductTitle::clean(title: null, host: 'ah.nl'))->toBeNull()
        ->and(ProductTitle::clean('Melk 1 L kopen'))->toBe('Melk 1 L')
        ->and(ProductTitle::clean('Melk 1 L | Albert Heijn'))->toBe('Melk 1 L | Albert Heijn');
});
