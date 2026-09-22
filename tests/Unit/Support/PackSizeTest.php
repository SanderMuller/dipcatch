<?php declare(strict_types=1);

use App\Support\PackSize;
use App\Support\StatedPackSize;

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
    expect(PackSize::parse('1 kg')->quantity)->toBe(1000.0)
        ->and(PackSize::parse('1 kilo')->quantity)->toBe(1000.0)
        ->and(PackSize::parse('1 kilogram')->quantity)->toBe(1000.0)
        ->and(PackSize::parse('1 gr')->quantity)->toBe(1.0);
});

test('normalizes dl to milliliters times 100', function (): void {
    expect(PackSize::parse('5 dl')->quantity)->toBe(500.0);
});

test('normalizes cl to milliliters times 10', function (): void {
    expect(PackSize::parse('5 cl')->quantity)->toBe(50.0);
});

test('normalizes liter aliases', function (): void {
    expect(PackSize::parse('1 ltr')->quantity)->toBe(1000.0)
        ->and(PackSize::parse('1 liter')->quantity)->toBe(1000.0);
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

test('counts coffee capsules, cups and cleaning pads as pieces', function (): void {
    expect(PackSize::parse('30 cups')->quantity)->toBe(30.0)
        ->and(PackSize::parse('30 cups')->unit)->toBe('piece')
        ->and(PackSize::parse('1 cup')->quantity)->toBe(1.0)
        ->and(PackSize::parse('30 capsules')->quantity)->toBe(30.0)
        ->and(PackSize::parse('30 capsules')->unit)->toBe('piece')
        ->and(PackSize::parse('1 capsule')->quantity)->toBe(1.0)
        ->and(PackSize::parse('36 pads')->quantity)->toBe(36.0)
        ->and(PackSize::parse('36 pads')->unit)->toBe('piece')
        ->and(PackSize::parse('1 pad')->quantity)->toBe(1.0);
});

test('a weight beside a capsule count still wins', function (): void {
    // Bucket precedence: the shopper compares grams, and the count is packaging
    // detail. Same rule the existing mass-over-pieces case proves.
    $size = PackSize::parse('Nescafé Dolce Gusto Lungo 30 capsules 216 g');

    expect($size->quantity)->toBe(216.0)
        ->and($size->unit)->toBe('g');
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

test('additive piece sizes parse to null', function (): void {
    expect(PackSize::parse('2 stuks + 2 stuks'))->toBeNull()
        ->and(PackSize::parse('4 rollen + 2 rollen'))->toBeNull();
});

// --- resolve: a shop that states one item's size -----------------------------

test('a stated size that is one item of a multipack in the title gives way to the pack', function (): void {
    // A clearance shop reported "55 g" for a box of twelve bars. Taken at
    // face value the box looks twelve times dearer per kilo than it is, and
    // that number is what best value and the unit-price alerts run on.
    $size = PackSize::resolve('55 g', authoritative: true, title: 'Barebells Protein Bar Cookies & Cream - 12 x 55 g');

    expect($size->quantity)->toBe(660.0)
        ->and($size->unit)->toBe('g');
});

test('the same holds for a unit the title spells differently', function (): void {
    $size = PackSize::resolve('45 gram', authoritative: true, title: 'Barebells Spread Bar - 12 x 45 gram');

    expect($size->quantity)->toBe(540.0)
        ->and($size->unit)->toBe('g');
});

test('a leading piece count with the size elsewhere is a multipack too', function (string $title): void {
    // Foodello writes every listing this way: "12st ... 55g", no x, no spaces.
    // A forced recheck reported 55 g for a box of twelve until this.
    expect(PackSize::resolve('55 g', authoritative: true, title: $title)->quantity)->toBe(660.0);
})->with([
    '12st Barebells Hazelnut Nougat eiwitrepen 55g',
    '12st-barebells-coco-choco-soft-protein-bar-55g',
    '12 stuks Barebells 55 g',
]);

test('a title that counts first and sizes last is a pack, with no stated size at all', function (string $title, float $expected): void {
    // The live case: Foodello states no structured size, so the title is all
    // there is, and it read as one 55 g bar instead of a box of twelve.
    expect(PackSize::resolve(packSize: null, authoritative: false, title: $title)->quantity)->toBe($expected);
})->with([
    'the shop house style' => ['12st Barebells Hazelnut Nougat eiwitrepen 55g', 660.0],
    'spelled out' => ['12 stuks 55 g', 660.0],
    // Order is the signal: this is 500 g holding twenty sachets.
    'size before the count' => ['Koffie 500 g 20 zakjes', 500.0],
    'a count with no size' => ['Eieren 12 stuks', 12.0],
    'no count at all' => ['Melk 1 L', 1000.0],
]);

test('a leading count needs one size in the title, not several', function (): void {
    // "and 1 kg free" says something this rule cannot read, so the shop's
    // own figure stands.
    expect(PackSize::resolve('55 g', authoritative: true, title: '12 stuks 55 g en 1 kg gratis')->quantity)->toBe(55.0);
});

test('a stated pack total is left alone', function (): void {
    $size = PackSize::resolve('660 g', authoritative: true, title: 'Barebells Protein Bar - 12 x 55 g');

    expect($size->quantity)->toBe(660.0);
});

test('a stated size the title does not contradict is left alone', function (string $stated, string $title, float $expected): void {
    expect(PackSize::resolve($stated, authoritative: true, title: $title)->quantity)->toBe($expected);
})->with([
    // The shop knows its own packaging better than a title does: only an
    // exact match against the multipack's own per-item size overrides it.
    'no multipack in the title' => ['55 g', 'Barebells single bar 55 g', 55.0],
    'a size that is not the item size' => ['500 g', 'Koffie 12 x 55 g', 500.0],
    'a plain single pack' => ['500 g', 'Koffiebonen 500 g', 500.0],
]);

test('an authoritative empty size still clears the pack data', function (): void {
    // Unchanged by the rule above: a shop that stops stating a size must be
    // able to clear a stale one (spec Section 4).
    expect(PackSize::resolve(packSize: null, authoritative: true, title: 'Barebells 12 x 55 g'))->toBeNull()
        ->and(PackSize::resolve(packSize: null, authoritative: false, title: 'Barebells 12 x 55 g')?->quantity)->toBe(660.0);
});

// --- a count written as a bare multiplier ------------------------------------

test('a bare count multiplier counts the items', function (): void {
    expect(StatedPackSize::bareCountIn('AURMOO 200x Garbage Bags 5L, Degradable'))->toBe(200.0);
});

test('a cross form is not read as a bare count', function (): void {
    expect(StatedPackSize::bareCountIn('Barebells 12 x 55 g'))->toBeNull();
});

test('a title counting two things states no bare count', function (): void {
    expect(StatedPackSize::bareCountIn('2x doos 200x zakken'))->toBeNull();
});

test('the unit price value is not rounded', function (): void {
    $size = PackSize::of(400.0, 'piece');

    expect($size->unitPriceValueFor('12.99'))->toBeGreaterThan(0.0324)
        ->and($size->unitPriceValueFor('12.99'))->toBeLessThan(0.0325)
        ->and($size->unitPriceFor('12.99'))->toBe('0.03');
});

test('a bare count multiplies the item size the shop stated', function (): void {
    // The AURMOO case: Amazon states 5 L, which is one bag, and the title
    // counts two hundred of them. Left alone it priced the box at 3.20 a litre.
    $size = PackSize::resolve('5 L', authoritative: true, title: 'AURMOO 200x Vuilniszakken 5L, Afbreekbaar');

    expect($size?->quantity)->toBe(1000000.0)
        ->and($size?->unit)->toBe('ml');
});
