<?php declare(strict_types=1);

use App\Enums\PackExclusion;
use App\Enums\PackProvenance;
use App\Models\Product;
use App\Models\Shop;
use App\Support\ComparablePack;
use App\Support\ComparablePacks;

/**
 * @param  array<string, array<string, mixed>>  $shops  host => attributes
 */
function productForPacks(array $shops, string $currency = 'EUR'): Product
{
    $product = Product::factory()->create(['currency' => $currency]);

    foreach ($shops as $host => $attributes) {
        Shop::factory()->for($product)->create([
            'url' => 'https://' . $host . '/p/' . bin2hex(random_bytes(4)),
        ])->forceFill(['currency' => $currency, ...$attributes])->save();
    }

    return $product->refresh();
}

function packsFor(Product $product): ComparablePacks
{
    return $product->comparablePacks();
}

function packFor(Product $product, string $host): ?ComparablePack
{
    $shop = $product->shops->firstWhere('host', $host);

    return $shop === null ? null : packsFor($product)->for($shop);
}

test('a stated size in the product unit is stated and may win', function (): void {
    $product = productForPacks([
        'ah.nl' => ['current_price' => '6.59', 'pack_quantity' => '840.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '6.15', 'pack_quantity' => '840.00', 'pack_unit' => 'g'],
    ]);

    expect(packsFor($product)->unit())->toBe('g')
        ->and(packFor($product, 'ah.nl')?->provenance)->toBe(PackProvenance::Stated)
        ->and(packFor($product, 'ah.nl')?->canWin())->toBeTrue()
        ->and(packFor($product, 'ah.nl')?->isExcluded())->toBeFalse();
});

test('a shop stating nothing inherits a size its siblings agree on, and may not win', function (): void {
    // barebells.nl, live: no size of its own, five siblings all at 660 g.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '20.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '21.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'barebells.nl' => ['current_price' => '19.50', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    $pack = packFor($product, 'barebells.nl');

    expect($pack?->provenance)->toBe(PackProvenance::Inferred)
        ->and($pack?->size?->quantity)->toBe(660.0)
        ->and($pack?->canWin())->toBeFalse()
        ->and($pack?->isExcluded())->toBeFalse();
});

test('a shop stating nothing is excluded when its siblings disagree', function (): void {
    // Pandy Sour Cola: 300 g at one shop, 700 g at another. The disagreement is
    // the signal that these shops sell different packs.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '3.00', 'pack_quantity' => '300.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '6.00', 'pack_quantity' => '700.00', 'pack_unit' => 'g'],
        'silent.nl' => ['current_price' => '4.00', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    expect(packFor($product, 'silent.nl')?->exclusion)->toBe(PackExclusion::SizeUnknown)
        ->and(packFor($product, 'silent.nl')?->reason())->toBe('Pack size unknown');
});

test('an inherited size that lands far below the field is refused as wrong', function (): void {
    // The 6-pack shape: siblings agree on 660 g, this shop sells half as much
    // for half the money, and inheriting 660 g would crown it.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '22.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '22.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'sixpack.nl' => ['current_price' => '11.00', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    expect(packFor($product, 'sixpack.nl')?->exclusion)->toBe(PackExclusion::SizeImplausible)
        ->and(packFor($product, 'sixpack.nl')?->reason())->toBe('Pack size looks wrong for this product');
});

test('a counted pack among weight-stating siblings is excluded, never converted', function (): void {
    // fitnesscandy states 12 piece. Sibling weight divided by the count and
    // multiplied back is the sibling weight, so conversion would assert the two
    // shops sell the same pack rather than measure anything.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '22.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '23.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'fitnesscandy.nl' => ['current_price' => '18.00', 'pack_quantity' => '12.00', 'pack_unit' => 'piece'],
    ]);

    $pack = packFor($product, 'fitnesscandy.nl');

    expect($pack?->exclusion)->toBe(PackExclusion::SoldByThePiece)
        ->and($pack?->size)->toBeNull()
        ->and($pack?->canWin())->toBeFalse();
});

test('a shop in another currency is excluded and says which', function (): void {
    $product = productForPacks([
        'ah.nl' => ['current_price' => '22.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '23.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
    ]);

    Shop::factory()->for($product)->create(['url' => 'https://uk.example.com/p/x'])
        ->forceFill(['currency' => 'GBP', 'current_price' => '15.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'])
        ->save();

    $pack = packFor($product->refresh(), 'uk.example.com');

    expect($pack?->exclusion)->toBe(PackExclusion::DifferentCurrency)
        ->and($pack?->reason())->toBe('Priced in GBP');
});

test('a shop measured in a unit the product does not compare in is excluded', function (): void {
    $product = productForPacks([
        'ah.nl' => ['current_price' => '2.00', 'pack_quantity' => '500.00', 'pack_unit' => 'ml'],
        'jumbo.com' => ['current_price' => '2.20', 'pack_quantity' => '500.00', 'pack_unit' => 'ml'],
        'dirk.nl' => ['current_price' => '2.10', 'pack_quantity' => '450.00', 'pack_unit' => 'g'],
    ]);

    expect(packsFor($product)->unit())->toBe('ml')
        ->and(packFor($product, 'dirk.nl')?->exclusion)->toBe(PackExclusion::UnitDoesNotConvert)
        ->and(packFor($product, 'dirk.nl')?->reason())->toBe('Measured in a different unit');
});

test('a product where no shop states a size has no comparison unit and no exclusions', function (): void {
    // The Dolce Gusto shape. Nothing to be excluded from, so stamping every row
    // with a reason would be noise.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '7.99', 'pack_quantity' => null, 'pack_unit' => null],
        'jumbo.com' => ['current_price' => '8.49', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    expect(packsFor($product)->hasComparisonUnit())->toBeFalse()
        ->and(packsFor($product)->unit())->toBeNull()
        ->and(packFor($product, 'ah.nl'))->toBeNull();
});

test('only shops with a stated size can win the ranking', function (): void {
    $product = productForPacks([
        'ah.nl' => ['current_price' => '22.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'barebells.nl' => ['current_price' => '19.50', 'pack_quantity' => null, 'pack_unit' => null],
        'fitnesscandy.nl' => ['current_price' => '18.00', 'pack_quantity' => '12.00', 'pack_unit' => 'piece'],
    ]);

    expect(packsFor($product)->winnable($product->shops)->pluck('host')->all())->toBe(['ah.nl']);
});

test('every exclusion reason renders copy a reader can act on', function (): void {
    foreach (PackExclusion::cases() as $reason) {
        expect($reason->label('GBP'))->not->toBeEmpty()
            ->and($reason->label('GBP'))->toBeString();
    }
});

test('the answer does not depend on the order the shops arrive in', function (): void {
    // The resolver pairs shops with their sizes by collection key, and the
    // plausibility median is taken over that pairing. A caller that hands it a
    // re-keyed collection must get the same answer, or an excluded shop sitting
    // first in insertion order silently empties the median.
    $product = productForPacks([
        'silent.nl' => ['current_price' => '19.50', 'pack_quantity' => null, 'pack_unit' => null],
        'ah.nl' => ['current_price' => '22.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '21.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
    ]);

    $reversed = ComparablePacks::of($product->shops->reverse()->values(), 'EUR');
    $silent = $product->shops->where('host', 'silent.nl')->sole();

    expect($reversed->unit())->toBe('g')
        ->and($reversed->for($silent)?->provenance)->toBe(packsFor($product)->for($silent)?->provenance)
        ->and($reversed->cheapestPerUnit($product->shops)?->host)
        ->toBe(packsFor($product)->cheapestPerUnit($product->shops)?->host);
});

test('shops that cannot be sold do not decide what the product is measured in', function (): void {
    // Two dead millilitre shops must not outvote the one live gram shop. If
    // they did, the live shop would read "measured in a different unit", the
    // product would have no best-value winner, and drop detection would be off
    // for it entirely.
    $product = productForPacks([
        'live.nl' => ['current_price' => '2.10', 'pack_quantity' => '450.00', 'pack_unit' => 'g'],
        'dead-one.nl' => ['current_price' => '2.00', 'pack_quantity' => '500.00', 'pack_unit' => 'ml', 'health' => 'dead'],
        'dead-two.nl' => ['current_price' => '2.20', 'pack_quantity' => '500.00', 'pack_unit' => 'ml', 'active' => false],
    ]);

    $packs = packsFor($product);

    expect($packs->unit())->toBe('g')
        ->and($product->bestValueShop()?->host)->toBe('live.nl');
});

test('a product keeps its comparison unit when its sized shops go out of stock', function (): void {
    // The Sanimed shape, live: two shops state 1200 g and both sold out, and the
    // cheapest shop states nothing. Letting stock decide the unit left every
    // shop on the product unresolvable — no size, no reason, nothing to compare.
    $product = productForPacks([
        'dierenapotheek.nl' => ['current_price' => '25.00', 'pack_quantity' => '1200.00', 'pack_unit' => 'g', 'current_in_stock' => false],
        'petmarkt.nl' => ['current_price' => '26.00', 'pack_quantity' => '1200.00', 'pack_unit' => 'g', 'current_in_stock' => false],
        'omnipet.be' => ['current_price' => '20.95', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    $packs = packsFor($product);

    expect($packs->unit())->toBe('g')
        // The silent shop inherits the size its siblings agree on, and says so.
        ->and(packFor($product, 'omnipet.be')?->provenance)->toBe(PackProvenance::Inferred)
        // But an inherited size may not win, and the two shops that could are
        // out of stock — so the product has no winner and drops fall back to
        // pack prices rather than stopping.
        ->and($product->bestValueShop())->toBeNull()
        ->and($product->dropComparisonUnit())->toBeNull();
});

test('every shop on a product with a comparison unit is resolved or told why not', function (): void {
    // The invariant section 6 rests on. A shop that is present, priced and in
    // stock is never silently absent from the comparison.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '22.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '23.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'fitnesscandy.nl' => ['current_price' => '18.00', 'pack_quantity' => '12.00', 'pack_unit' => 'piece'],
        'barebells.nl' => ['current_price' => '21.00', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    $packs = packsFor($product);

    foreach ($product->shops as $shop) {
        $pack = $packs->for($shop);

        expect($pack)->not->toBeNull()
            ->and($pack->size !== null || $pack->reason() !== null)->toBeTrue();
    }
});

test('an inherited size right on the plausibility boundary is kept', function (): void {
    // Strictly below 60% of the field median is refused. Exactly on it is not:
    // a shop can legitimately be 40% cheaper, and the guard exists for sizes
    // that are wrong, not for offers that are good.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '20.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '20.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
        'silent.nl' => ['current_price' => '12.00', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    // Median is 20.00/kg; 12.00 for the same inherited kilo is exactly 60%.
    expect(packFor($product, 'silent.nl')?->provenance)->toBe(PackProvenance::Inferred);

    $product->shops->where('host', 'silent.nl')->sole()->forceFill(['current_price' => '11.99'])->save();

    expect(packFor($product->refresh(), 'silent.nl')?->exclusion)->toBe(PackExclusion::SizeImplausible);
});

test('an inherited size far above the field is refused too', function (): void {
    // The guard used to be one-sided, reasoning that a row stamped too
    // expensive cannot win and cannot alert, so refusing it would remove
    // information without protecting anyone. That was wrong about what a
    // reader acts on: reported 2026-09-22, a 150-tablet pack that inherited a
    // sibling's 75 was displayed at twice its real price per tablet, so the
    // best deal on the product read as the worst. It never won, and it did not
    // need to in order to mislead.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '20.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '20.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
        'silent.nl' => ['current_price' => '80.00', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    expect(packFor($product, 'silent.nl')?->exclusion)->toBe(PackExclusion::SizeImplausible);
});

test('an inherited size merely dearer than the field is kept', function (): void {
    // The band is the same distance either side of the median, so an ordinary
    // dearer shop keeps its figure. Median is 20.00/kg; 30.00 sits inside.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '20.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '20.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
        'silent.nl' => ['current_price' => '30.00', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    expect(packFor($product, 'silent.nl')?->provenance)->toBe(PackProvenance::Inferred);
});

test('a shop free of charge does not win best value', function (): void {
    // A unit price needs a price above zero. Without the guard a 0.00 row sorts
    // ahead of everything, because a missing unit price cast to a number is the
    // smallest number there is.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '20.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
        'broken.nl' => ['current_price' => '0.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('ah.nl');
});

test('a stated size nobody can read says so rather than borrowing one', function (): void {
    // Inheriting here would paper over a bad row with a plausible number.
    $product = productForPacks([
        'ah.nl' => ['current_price' => '20.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '21.00', 'pack_quantity' => '1000.00', 'pack_unit' => 'g'],
        'broken.nl' => ['current_price' => '19.00', 'pack_quantity' => '500.00', 'pack_unit' => 'furlong'],
    ]);

    expect(packFor($product, 'broken.nl')?->exclusion)->toBe(PackExclusion::SizeUnknown);
});

test('two shops tied on unit price resolve to the one added first', function (): void {
    // Arbitrary ordering previously generated spurious history segments and
    // re-anchored drop detection. The tie-break has to survive the move out of
    // SQL, and here the two shops sell different pack sizes at the same rate.
    $product = Product::factory()->create(['currency' => 'EUR']);

    $first = Shop::factory()->for($product)->create(['url' => 'https://first.nl/p/1']);
    $first->forceFill([
        'currency' => 'EUR', 'current_price' => '10.00',
        'pack_quantity' => '500.00', 'pack_unit' => 'g',
        'created_at' => now()->subDays(3),
    ])->save();

    $second = Shop::factory()->for($product)->create(['url' => 'https://second.nl/p/1']);
    $second->forceFill([
        'currency' => 'EUR', 'current_price' => '20.00',
        'pack_quantity' => '1000.00', 'pack_unit' => 'g',
        'created_at' => now()->subDay(),
    ])->save();

    $product->refresh()->recomputeCheapestShop();

    expect($product->refresh()->best_value_shop_id)->toBe($first->id);

    // And it stays there across a recompute rather than flipping.
    $product->refresh()->recomputeCheapestShop();

    expect($product->refresh()->best_value_shop_id)->toBe($first->id);
});

test('the two answers are drawn from the same set of buyable shops', function (): void {
    // `winnable()` filters on provenance and a usable unit price — it knows
    // nothing about stock or health, because that lives in the eligible set. A
    // caller that hands it every shop gets a winner no other surface agrees
    // with, which is the divergence one shared definition exists to prevent.
    $product = productForPacks([
        'live.nl' => ['current_price' => '10.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g'],
        'dead.nl' => ['current_price' => '1.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'health' => 'dead'],
        'paused.nl' => ['current_price' => '2.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'active' => false],
        'gone.nl' => ['current_price' => '3.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'current_in_stock' => false],
    ]);

    expect($product->bestValueShop()?->host)->toBe('live.nl')
        ->and($product->lowestOutlayShop()?->host)->toBe('live.nl');

    $product->recomputeCheapestShop();

    // And the stored answers agree with the live ones.
    expect($product->refresh()->best_value_shop_id)->toBe($product->bestValueShop()?->id)
        ->and($product->cheapest_shop_id)->toBe($product->lowestOutlayShop()?->id);
});
