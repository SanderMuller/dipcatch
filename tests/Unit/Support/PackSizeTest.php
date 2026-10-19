<?php declare(strict_types=1);

use App\Support\ItemCount;
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

test('parses the English piece words Amazon titles a pack with', function (string $title, float $count): void {
    $size = PackSize::resolve(packSize: null, authoritative: false, title: $title);

    expect($size?->quantity)->toBe($count)
        ->and($size?->unit)->toBe('piece');
})->with([
    'pieces' => ['PETKIT Filters for cats, 5 pieces for EverSWEET 2, 3 and CYBERTIAL PUREDRINK fountains', 5.0],
    'count' => ['Vitamin D3 2000 IU, 60 Count', 60.0],
    'tablets' => ['Dishwasher tablets, 40 tablets', 40.0],
    'pcs' => ['Coffee pods 10 pcs', 10.0],
]);

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

    expect($size->unitPriceFor('1.69'))->toBe('8.4500');
});

test('unit price for a piece pack size', function (): void {
    $size = PackSize::parse('4 rollen');

    expect($size->unitPriceFor('1.80'))->toBe('0.4500');
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
    'a count of what is inside, first' => ['Nescafé Dolce Gusto Lungo 30 capsules 216 g', 216.0],
    'a pack count beside a count of what is inside' => ['2 stuks Koffie 500 g 20 zakjes', 1000.0],
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
    // Order is the signal here too: one 500 g bag holding twenty sachets.
    'a count after the size' => ['500 g', 'Koffie 500 g 20 zakjes', 500.0],
    'two counts, neither the pack' => ['5 L', '2x doos 200x zakken 5L', 5000.0],
    'a set beside a count after the size' => ['500 g', 'Koffie 500 g, 20 zakjes, set van 2', 1000.0],
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
        ->and($size->unitPriceFor('12.99'))->toBe('0.0325');
});

test('a bare count multiplies the item size the shop stated', function (): void {
    // The AURMOO case: Amazon states 5 L, which is one bag, and the title
    // counts two hundred of them. Left alone it priced the box at 3.20 a litre.
    $size = PackSize::resolve('5 L', authoritative: true, title: 'AURMOO 200x Vuilniszakken 5L, Afbreekbaar');

    expect($size?->quantity)->toBe(1000000.0)
        ->and($size?->unit)->toBe('ml');
});

test('a title that names a set or pack counts its items, in every shop language', function (?string $stated, bool $authoritative, string $title, float $expected): void {
    // The Xenos case: a set of four 350 ml glasses read as 350 ml, so it
    // looked four times dearer per litre than the single glass beside it.
    expect(PackSize::resolve($stated, authoritative: $authoritative, title: $title)?->quantity)->toBe($expected);
})->with([
    'the title alone' => [null, false, 'Dubbelwandige glazen - 350 ml - set van 4', 1400.0],
    'a stated item size' => ['350 ml', true, 'Dubbelwandige glazen - 350 ml - set van 4', 1400.0],
    'in English' => [null, false, 'Drinking glasses 250 ml, set of 6', 1500.0],
    'an Amazon pack' => ['330 ml', true, 'Coca-Cola Zero 330 ml (Pack of 24)', 7920.0],
    'in German' => [null, false, 'Trinkgläser 300 ml, 6er-Set', 1800.0],
    'in German, spelled out' => [null, false, 'Gläser Set mit 4 Stück 350 ml', 1400.0],
    'in French' => [null, false, 'Verres 250 ml - lot de 6', 1500.0],
    'in Italian' => [null, false, 'Bicchieri 300 ml confezione da 6', 1800.0],
    // A pan set is pans of several sizes, not several of one.
    'a numbered set of parts' => [null, false, 'Pannenset 3-delig 2,5 l', 2500.0],
    'two different set counts' => [null, false, 'Set van 2, set of 4, 350 ml', 350.0],
    'a set of one' => [null, false, 'Glas 350 ml - set van 1', 350.0],
    // The cross form already counts the items; the set must not count them again.
    'beside a cross form' => [null, false, 'Set van 2 x 500 ml', 1000.0],
    'a stated pack total' => ['1400 ml', true, 'Dubbelwandige glazen - 350 ml - set van 4', 1400.0],
]);

test('a piece count is read in the languages of the shops beyond the Netherlands', function (string $title, float $expected): void {
    expect(PackSize::parse($title)?->quantity)->toBe($expected)
        ->and(PackSize::parse($title)?->unit)->toBe('piece');
})->with([
    ['Teelichter 50 Stück', 50.0],
    ['Kaffeepads 36 Stueck', 36.0],
    ['Bougies chauffe-plat 30 pièces', 30.0],
    ['Capsule caffè 10 pezzi', 10.0],
    ['Cápsulas 16 unidades', 16.0],
]);

test('a multipack written size first counts the items', function (string $title, float $quantity, float $items): void {
    // British and Amazon listings write "330ml x 24". Read as one can, the
    // pack looked 24 times dearer per litre than it is.
    $size = PackSize::resolve(packSize: null, authoritative: false, title: $title);

    expect($size?->quantity)->toBe($quantity)
        ->and(ItemCount::in($title))->toBe($items);
})->with([
    ['Coca-Cola 330ml x 24', 7920.0, 24.0],
    ['Glazen 350 ml x4', 1400.0, 4.0],
    ['Pepsi Max 24 x 330 ml', 7920.0, 24.0],
]);

test('a size-first multiplier needs a bare whole count', function (string $title, ?float $expected): void {
    expect(PackSize::resolve(packSize: null, authoritative: false, title: $title)?->quantity)->toBe($expected);
})->with([
    // A decimal is a second size, not a count.
    'a decimal after the x' => ['Suiker 1 kg x 2,5', 1000.0],
    'no unit before the x' => ['Plank 20 cm x 30', null],
    // A length after the count measures the item; it counts nothing.
    'a length after the count' => ['Bakvorm 2 l x 24 cm', 2000.0],
    'a length in inches' => ['Koelbox 24 l x 40 inch', 24000.0],
    // The free one is not part of a count: no size-first form reads it.
    'a free one beside it' => ['Sticks 50 g x 3 + 1 gratis', 50.0],
]);

test('a shop stating the whole of a dash pack keeps it, counted once', function (string $stated, string $title, float $expected): void {
    expect(PackSize::resolve($stated, authoritative: true, title: $title)?->quantity)->toBe($expected);
})->with([
    ['1980 ml', 'Cola 6-pack 330 ml', 1980.0],
    ['7,92 l', 'Bier 24-pack 7,92 l', 7920.0],
    ['9 l', 'Pepsi Max 6-pack 9 l', 9000.0],
]);

test('a stated size beside a length is not multiplied by it', function (): void {
    expect(PackSize::resolve('2 l', authoritative: true, title: 'Bakvorm 2 l x 24 cm')?->quantity)->toBe(2000.0);
});

test('a dash pack counts its items as well as sizing the pack', function (): void {
    expect(PackSize::resolve(packSize: null, authoritative: false, title: 'Mugs 4-pack 350 ml')?->quantity)->toBe(1400.0)
        ->and(ItemCount::ofSecondSize('Mugs 4-pack 350 ml'))->toBe(4.0);
});

test('a count after the size is the number of items only for a thing, not its contents', function (string $title, float $contents, float $thing): void {
    // "500 g 20 zakjes" is one 500 g bag of twenty pads; "350 ml, 4 pcs" is
    // four glasses. The words cannot tell them apart; the department can.
    expect(PackSize::resolve(packSize: null, authoritative: false, title: $title)?->quantity)->toBe($contents)
        ->and(PackSize::resolve(packSize: null, authoritative: false, title: $title, countsItems: true)?->quantity)->toBe($thing);
})->with([
    ['Glasses, 350 ml, 4 pcs', 350.0, 1400.0],
    ['Gläser 350 ml 4 Stück', 350.0, 1400.0],
    ['Mok 350 ml (4 stuks)', 350.0, 1400.0],
    // Counted already: the department adds nothing.
    ['Mugs 4-pack 350 ml', 1400.0, 1400.0],
    ['Glazen 350 ml x4', 1400.0, 1400.0],
]);

test('a stated item size takes a count of what is inside only for a thing', function (string $stated, string $title, float $contents, float $thing): void {
    // "500 g 20 zakjes" is one bag of twenty sachets; "12 stuks", "12 Pack"
    // and "200x" count packs, wherever they stand.
    expect(PackSize::resolve($stated, authoritative: true, title: $title)?->quantity)->toBe($contents)
        ->and(PackSize::resolve($stated, authoritative: true, title: $title, countsItems: true)?->quantity)->toBe($thing);
})->with([
    ['500 g', 'Koffie 500 g 20 zakjes', 500.0, 10000.0],
    ['216 g', 'Nescafé Dolce Gusto Lungo 30 capsules 216 g', 216.0, 6480.0],
    ['720 g', 'Vaatwastabletten 40 tabletten 720 g', 720.0, 28800.0],
    ['55 g', 'Barebells 55 g 12 stuks', 660.0, 660.0],
    ['5 L', 'Vuilniszakken 5L 200x', 1000000.0, 1000000.0],
    ['40 g', 'Pringles 40 g (12 Pack)', 480.0, 480.0],
    ['350 ml', 'Mok 350 ml (4 stuks)', 1400.0, 1400.0],
    ['5 L', '2x doos 200x zakken 5L', 5000.0, 5000.0],
]);

// --- Dutch drugstore count abbreviations -------------------------------------

test('a count written as a drugstore abbreviation is read', function (string $title, float $expected): void {
    // deonlinedrogist.nl writes every listing this way, and so do its
    // neighbours. Unread, the row inherits a sibling's size instead.
    expect(PackSize::parse($title)?->quantity)->toBe($expected)
        ->and(PackSize::parse($title)?->unit)->toBe('piece');
})->with([
    ['Davitamon Vitamine D3 20mcg Smelttabletten 150TB', 150.0],
    ['Davitamon Vitamine D3 20mcg Smelttabletten 75TB', 75.0],
    ['Roter Vitamine C 400 TABL', 400.0],
    ['Omega 3 visolie 60 caps', 60.0],
    ['Magnesium 90 CPS', 90.0],
    ['Thee 20 SACH', 20.0],
    ['Bouillon 6 STK', 6.0],
]);

test('a chewable, effervescent or lozenge tablet count is read', function (string $title, float $expected): void {
    // Unread, a koopjesdrogisterij.nl row inherited 800 from its siblings,
    // and its real €8.80 for 800 then read as a size that looks wrong.
    expect(PackSize::parse($title)?->quantity)->toBe($expected)
        ->and(PackSize::parse($title)?->unit)->toBe('piece');
})->with([
    ['Roter Vitamine C 70 mg kauwtablet (800 kauwtabletten)', 800.0],
    ['Davitamon Junior 60 kauwtabletten', 60.0],
    ['Redoxon Vitamine C 20 bruistabletten', 20.0],
    ['Strepsils Honing citroen 24 zuigtabletten', 24.0],
    // A singular is a dose, not a count.
    ['Multivitamine 1 kauwtablet per dag 60 stuks', 60.0],
]);

test('a terabyte is not a tablet', function (string $title): void {
    // `TB` is seventy-five tablets at a drugstore and two thousand gigabytes
    // at an electronics shop. Reading a disk as two tablets would price it per
    // half-disk.
    expect(PackSize::parse($title))->toBeNull();
})->with([
    'Samsung 870 EVO 2TB SSD',
    'Crucial P3 1TB NVMe',
    'Externe harde schijf 4TB',
    'SanDisk 512 GB micro SD',
]);

test('a disk that states a real size still reads it', function (): void {
    // The terabyte is dropped, not the whole string.
    expect(PackSize::parse('Samsung 870 EVO 2TB SSD 500 g')?->quantity)->toBe(500.0);
});
