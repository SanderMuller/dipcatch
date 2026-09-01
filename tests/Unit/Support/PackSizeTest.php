<?php declare(strict_types=1);

use App\Support\PackSize;

// --- Basic units, aliases, comma decimals -------------------------------

test('parses a plain mass size', function (): void {
    $size = PackSize::parse('150 g');

    expect($size->quantity)->toBe(150.0)
        ->and($size->unit)->toBe('g');
});

test('parses attached mass form without a space', function (): void {
    $size = PackSize::parse('500gram');

    expect($size->quantity)->toBe(500.0)
        ->and($size->unit)->toBe('g');
});

test('parses attached, uppercase volume form', function (): void {
    $size = PackSize::parse('330ML');

    expect($size->quantity)->toBe(330.0)
        ->and($size->unit)->toBe('ml');
});

test('parses comma decimals', function (): void {
    $size = PackSize::parse('0,75 l');

    expect($size->quantity)->toBe(750.0)
        ->and($size->unit)->toBe('ml');
});

test('normalizes kg to grams', function (): void {
    expect(PackSize::parse('1 kg')->quantity)->toBe(1000.0);
    expect(PackSize::parse('1 kilo')->quantity)->toBe(1000.0);
    expect(PackSize::parse('1 kilogram')->quantity)->toBe(1000.0);
    expect(PackSize::parse('1 gr')->quantity)->toBe(1.0);
});

test('normalizes dl to milliliters times 100', function (): void {
    expect(PackSize::parse('5 dl')->quantity)->toBe(500.0);
});

test('normalizes cl to milliliters times 10', function (): void {
    expect(PackSize::parse('5 cl')->quantity)->toBe(50.0);
});

test('normalizes liter aliases', function (): void {
    expect(PackSize::parse('1 ltr')->quantity)->toBe(1000.0);
    expect(PackSize::parse('1 liter')->quantity)->toBe(1000.0);
});

test('parses a piece size from the title', function (): void {
    $size = PackSize::parse('HiPRO Protein Drink Mango 300ml');

    expect($size->quantity)->toBe(300.0)
        ->and($size->unit)->toBe('ml');
});

// --- Piece vocabulary ----------------------------------------------------

test('parses stuks', function (): void {
    $size = PackSize::parse('20 stuks');

    expect($size->quantity)->toBe(20.0)
        ->and($size->unit)->toBe('piece');
});

test('parses rollen', function (): void {
    $size = PackSize::parse('4 rollen');

    expect($size->quantity)->toBe(4.0)
        ->and($size->unit)->toBe('piece');
});

test('lone vellen counts as pieces', function (): void {
    $size = PackSize::parse('200 vellen');

    expect($size->quantity)->toBe(200.0)
        ->and($size->unit)->toBe('piece');
});

// --- Zero / invalid quantities --------------------------------------------

test('zero mass quantity parses to null', function (): void {
    expect(PackSize::parse('0 g'))->toBeNull();
});

test('zero piece quantity parses to null', function (): void {
    expect(PackSize::parse('0 stuks'))->toBeNull();
});

test('fractional piece count parses to null', function (): void {
    expect(PackSize::parse('2,5 stuks'))->toBeNull();
});

// --- Empty / missing input -------------------------------------------------

test('null text parses to null', function (): void {
    expect(PackSize::parse(text: null))->toBeNull();
});

test('empty text parses to null', function (): void {
    expect(PackSize::parse(''))->toBeNull();
});

// --- Whole-string rejects: ranges and additive sizes ----------------------

test('a hyphen range parses to null', function (): void {
    expect(PackSize::parse('500-600 g'))->toBeNull();
});

test('an en-dash range with a prefix parses to null', function (): void {
    expect(PackSize::parse('ca. 500–600 g'))->toBeNull();
});

test('an additive size parses to null', function (): void {
    expect(PackSize::parse('200 g + 150 g'))->toBeNull();
});

// --- Misleading numbers: + and % suffixes never start a size token -------

test('a percent-suffixed number never matches', function (): void {
    expect(PackSize::parse('40% minder zout'))->toBeNull();
});

test('a plus-suffixed cheese fat marker alone parses to null', function (): void {
    expect(PackSize::parse('48+ plakken'))->toBeNull();
});

test('plakken is excluded from the piece vocabulary entirely', function (): void {
    expect(PackSize::parse('Beemster Kaas extra belegen 48+ plakken'))->toBeNull();
});

// --- Multipacks: x / × / à --------------------------------------------------

test('an x multipack multiplies volume', function (): void {
    $size = PackSize::parse('6 x 250 ml');

    expect($size->quantity)->toBe(1500.0)
        ->and($size->unit)->toBe('ml');
});

test('a bare-x multipack (no spaces) multiplies pieces', function (): void {
    $size = PackSize::parse('3x10 stuks');

    expect($size->quantity)->toBe(30.0)
        ->and($size->unit)->toBe('piece');
});

test('an x multipack multiplies pieces', function (): void {
    $size = PackSize::parse('2 x 4 rollen');

    expect($size->quantity)->toBe(8.0)
        ->and($size->unit)->toBe('piece');
});

test('an à multipack multiplies volume', function (): void {
    $size = PackSize::parse('6 blikjes à 330 ml');

    expect($size->quantity)->toBe(1980.0)
        ->and($size->unit)->toBe('ml');
});

test('a × multipack with cl and trailing noise resolves', function (): void {
    $size = PackSize::parse('6 x 33 cl krat 24');

    expect($size->quantity)->toBe(1980.0)
        ->and($size->unit)->toBe('ml');
});

// --- -pack multipack --------------------------------------------------------

test('a bare -pack resolves to pieces', function (): void {
    $size = PackSize::parse('6-pack');

    expect($size->quantity)->toBe(6.0)
        ->and($size->unit)->toBe('piece');
});

test('-pack next to a size multiplies the size, never beats it', function (): void {
    $size = PackSize::parse('6-pack 330 ml');

    expect($size->quantity)->toBe(1980.0)
        ->and($size->unit)->toBe('ml');
});

// --- Two or more multipack patterns => null --------------------------------

test('two multipack patterns in one string parse to null', function (): void {
    expect(PackSize::parse('2 x 100 g en 2 x 50 g'))->toBeNull();
});

// --- Vel-drop rule -----------------------------------------------------------

test('vel is dropped when another piece token is present', function (): void {
    $size = PackSize::parse('8 rollen à 200 vel');

    expect($size->quantity)->toBe(8.0)
        ->and($size->unit)->toBe('piece');
});

// --- Bucket precedence: mass > volume > pieces, single-distinct-token rule -

test('mass wins over an accompanying piece token', function (): void {
    $size = PackSize::parse('6 stuks 300 g');

    expect($size->quantity)->toBe(300.0)
        ->and($size->unit)->toBe('g');
});

test('mass wins over an accompanying excluded fat-percentage marker', function (): void {
    $size = PackSize::parse('48+ plakken 150 g');

    expect($size->quantity)->toBe(150.0)
        ->and($size->unit)->toBe('g');
});

test('two distinct masses outside a multipack parse to null', function (): void {
    expect(PackSize::parse('500 g 300 g'))->toBeNull();
});

// --- unitPriceFor math -------------------------------------------------------

test('unit price for a mass pack size', function (): void {
    $size = PackSize::parse('200 g');

    expect($size->unitPriceFor('1.69'))->toBe('8.45');
});

test('unit price for a piece pack size', function (): void {
    $size = PackSize::parse('4 rollen');

    expect($size->unitPriceFor('1.80'))->toBe('0.45');
});

test('unit price is null for a zero price', function (): void {
    $size = PackSize::parse('200 g');

    expect($size->unitPriceFor('0'))->toBeNull();
});

test('unit price is null for a non-numeric price', function (): void {
    $size = PackSize::parse('200 g');

    expect($size->unitPriceFor('abc'))->toBeNull();
});

// --- label() -------------------------------------------------------------

test('label for mass is per kg', function (): void {
    expect(PackSize::parse('200 g')->label())->toBe('/kg');
});

test('label for volume is per liter', function (): void {
    expect(PackSize::parse('1 l')->label())->toBe('/l');
});

test('label for pieces is per stuk', function (): void {
    expect(PackSize::parse('4 rollen')->label())->toBe('/stuk');
});
