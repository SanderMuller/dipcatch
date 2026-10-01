<?php declare(strict_types=1);

use App\Enums\DepthSource;
use App\Enums\ProductCategory;
use App\Enums\PromotionDepthBand;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\BundleOffer;
use App\Support\AlertSuggestion\AlertSuggestion;
use App\Support\Numeric;
use App\Support\UnitTargetGuide;
use Carbon\CarbonImmutable;

/**
 * A product with the given shops: each row maps shop columns, plus an optional
 * `url`. The shops sell 1 kg unless a row says otherwise.
 *
 * @param  list<array<string, mixed>>  $shops
 */
function alertSuggestionProduct(array $shops, ?ProductCategory $category = null): Product
{
    $product = Product::factory()->create(['currency' => 'EUR', 'category' => $category]);

    foreach ($shops as $i => $columns) {
        $url = $columns['url'] ?? null;
        unset($columns['url']);

        Shop::factory()->for($product)->create([
            'url' => is_string($url) ? $url : "https://shop{$i}.nl/p/1",
            'currency' => 'EUR',
            'current_in_stock' => true,
            'pack_quantity' => '1000.00',
            'pack_unit' => 'g',
        ])->forceFill($columns)->save();
    }

    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

it('measures a target from the normal price, not from a promotion on now', function (): void {
    // AH is 25% off now: €3.00 against a claimed €4.00. Jumbo asks €4.20.
    $product = alertSuggestionProduct([
        ['url' => 'https://ah.nl/p/1', 'current_price' => '3.00', 'claimed_regular_price' => '4.00'],
        ['url' => 'https://jumbo.com/p/1', 'current_price' => '4.20'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product, PromotionDepthBand::Deep);

    expect($suggestion->normalUnitPrice)->toBe('4.0000')
        ->and($suggestion->depth)->toBe(35)
        ->and($suggestion->depthSource)->toBe(DepthSource::Jev)
        // 35% under €4.00, not 35% under the €3.00 promotion (€1.95).
        ->and($suggestion->unitTarget)->toBe('2.6')
        ->and($suggestion->promotionNowHost)->toBe('ah.nl')
        ->and($suggestion->promotionNowDepth)->toBe(25)
        ->and($suggestion->alreadyMet)->toBeFalse();
});

it('reads the normal price of a live bundle from its single item', function (): void {
    // 2 for €6.00 on a €4.00 item: €3.00 each, 25% off.
    $product = alertSuggestionProduct([
        ['current_price' => '3.00', 'single_item_price' => '4.00', 'bundle_quantity' => 2, 'bundle_total_price' => '6.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('4.0000')
        ->and($suggestion->promotionNowDepth)->toBe(25)
        ->and($suggestion->depthSource)->toBe(DepthSource::Category)
        ->and($suggestion->unitTarget)->toBe('2.6');
});

it('bases a product sold in several sizes on its best-value pack', function (): void {
    $product = alertSuggestionProduct([
        ['current_price' => '14.00', 'pack_quantity' => '3000.00'],
        ['current_price' => '36.00', 'pack_quantity' => '10000.00'],
        ['current_price' => '64.00', 'pack_quantity' => '20000.00'],
    ], ProductCategory::PetFood);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('3.2000')
        ->and($suggestion->normalPack)->toBe('20 kg')
        ->and($suggestion->depth)->toBe(20)
        ->and($suggestion->unitTarget)->toBe('2.56')
        ->and($suggestion->packTarget)->toBe('51.20');
});

it('takes the depth of a promotion deeper than the prior, capped', function (string $now, int $quantity, string $total, int $depth): void {
    $product = alertSuggestionProduct([
        ['current_price' => $now, 'single_item_price' => '4.00', 'bundle_quantity' => $quantity, 'bundle_total_price' => $total],
    ], ProductCategory::PetFood);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->depth)->toBe($depth)
        ->and($suggestion->depthSource)->toBe(DepthSource::PromotionNow)
        // 60% is the suggestion's ceiling, not a law.
        ->and($suggestion->cappedByLaw)->toBeFalse();
})->with([
    '1+1 free, 50%' => ['2.00', 2, '4.00', 50],
    '2+3 free at 60%, the cap' => ['1.60', 5, '8.00', 60],
    '1+2 free at 66% stops at the cap' => ['1.33', 3, '4.00', 60],
]);

it('caps alcohol at the 25% the law allows', function (): void {
    $product = alertSuggestionProduct([
        ['current_price' => '0.90', 'claimed_regular_price' => '1.20', 'pack_unit' => 'ml'],
    ], ProductCategory::Alcohol);

    $suggestion = AlertSuggestion::for($product, PromotionDepthBand::HalfOrMore);

    expect($suggestion->depth)->toBe(25)
        ->and($suggestion->cappedByLaw)->toBeTrue()
        ->and($suggestion->unitTarget)->toBe('0.9')
        // €0.90 now is the target already.
        ->and($suggestion->alreadyMet)->toBeTrue();
});

it('does not believe a "was" price in general merchandise', function (): void {
    // €70 today, "was €100": the normal price is unknown, so no 30% under €70.
    $product = alertSuggestionProduct([
        ['current_price' => '70.00', 'claimed_regular_price' => '100.00', 'pack_unit' => 'piece', 'pack_quantity' => '1.00'],
    ], ProductCategory::SmallAppliances);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->unitTarget)->toBeNull()
        ->and($suggestion->normalUnitPrice)->toBeNull();
});

it('leaves out a shop whose promotion hides its normal price', function (): void {
    // Aldi-style: a running window, no "was" price. The other shop sets the basis.
    $product = alertSuggestionProduct([
        ['current_price' => '2.00', 'promotion_starts_at' => CarbonImmutable::now()->subDay(), 'promotion_ends_at' => CarbonImmutable::now()->addDays(3)],
        ['current_price' => '4.00'],
    ], ProductCategory::Pantry);

    expect(AlertSuggestion::for($product)->normalUnitPrice)->toBe('4.0000');
});

it('reads an announced bonus that has not started as the regular price', function (): void {
    $product = alertSuggestionProduct([
        ['current_price' => '4.00', 'promotion_starts_at' => CarbonImmutable::now()->addDay(), 'promotion_ends_at' => CarbonImmutable::now()->addDays(7)],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('4.0000')
        ->and($suggestion->promotionNowDepth)->toBe(0);
});

it('checks a stored "was" price against today\'s shelf price', function (): void {
    // A €4.00 claim kept from an earlier reading, while the shelf is €5.00 now.
    $product = alertSuggestionProduct([
        ['current_price' => '5.00', 'claimed_regular_price' => '4.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('5.0000')
        ->and($suggestion->promotionNowDepth)->toBe(0);
});

it('lets a confident Jev band override the category', function (): void {
    $product = alertSuggestionProduct([['current_price' => '4.00']], ProductCategory::PetFood);

    $suggestion = AlertSuggestion::for($product, PromotionDepthBand::HalfOrMore);

    expect($suggestion->depth)->toBe(50)
        ->and($suggestion->depthSource)->toBe(DepthSource::Jev);
});

it('suggests no target for a fixed-price product, whatever a shop shows', function (): void {
    // Baby food believes "was" prices, so only the band stops the 25% on now.
    $product = alertSuggestionProduct([
        ['current_price' => '3.00', 'claimed_regular_price' => '4.00'],
    ], ProductCategory::BabyFood);

    $suggestion = AlertSuggestion::for($product, PromotionDepthBand::Fixed);

    expect($suggestion->normalUnitPrice)->toBe('4.0000')
        ->and($suggestion->unitTarget)->toBeNull()
        ->and($suggestion->depth)->toBe(0)
        ->and($suggestion->depthSource)->toBe(DepthSource::None);
});

it('suggests no target without a category, a band or a promotion', function (): void {
    $suggestion = AlertSuggestion::for(alertSuggestionProduct([['current_price' => '4.00']]));

    expect($suggestion->unitTarget)->toBeNull()
        ->and($suggestion->depthSource)->toBe(DepthSource::None)
        ->and($suggestion->normalUnitPrice)->toBe('4.0000');
});

it('suggests no target when no shop states a pack size', function (): void {
    $suggestion = AlertSuggestion::for(alertSuggestionProduct([['current_price' => '4.00', 'pack_quantity' => null, 'pack_unit' => null]], ProductCategory::Pantry));

    expect($suggestion->unitTarget)->toBeNull()
        ->and($suggestion->normalUnitPrice)->toBeNull();
});

it('suggests no target below the smallest one the column keeps', function (): void {
    // €0.01 for 1000 pieces is a thousandth of a cent each.
    $product = alertSuggestionProduct([['current_price' => '0.01', 'pack_unit' => 'piece', 'pack_quantity' => '1000.00']], ProductCategory::Pantry);

    expect(AlertSuggestion::for($product)->unitTarget)->toBeNull();
});

it('suggests no target for a product with no shop', function (): void {
    $product = Product::factory()->create(['category' => ProductCategory::Pantry]);

    expect(AlertSuggestion::for($product)->unitTarget)->toBeNull();
});

it('falls back to the suggested category', function (): void {
    $product = alertSuggestionProduct([['current_price' => '4.00']]);
    $product->forceFill(['suggested_category' => ProductCategory::CoffeeTea])->save();

    $suggestion = AlertSuggestion::for($product->refresh());

    expect($suggestion->depth)->toBe(40)
        ->and($suggestion->category)->toBe(ProductCategory::CoffeeTea);
});

it('never bases the target on a shop that only inherits its pack size', function (): void {
    // The unsized €6 shop can borrow the 1 kg size to compare, but it can never
    // win best value, so the unit target could never fire through it.
    $product = alertSuggestionProduct([
        ['current_price' => '10.00'],
        ['current_price' => '6.00', 'pack_quantity' => null, 'pack_unit' => null],
    ], ProductCategory::Pantry);

    $unsized = $product->shops()->where('current_price', '6.00')->sole();

    // The premise: it does borrow a size.
    expect($product->comparablePacks()->for($unsized)?->size)->not->toBeNull()
        ->and(AlertSuggestion::for($product)->normalUnitPrice)->toBe('10.0000');
});

it('suggests a target a promotion priced in cents reaches again', function (string $total, int $worth, int $depth): void {
    // The alert checks the per-item price rounded to the cent (€1.50 for a
    // 1+1 on €2.99), so the step comes from that, and the offer still meets it.
    $offer = new BundleOffer(2, $total);
    $product = alertSuggestionProduct([
        ['current_price' => $offer->effectiveUnitPrice(), 'single_item_price' => '2.99', 'bundle_quantity' => 2, 'bundle_total_price' => $total],
    ], ProductCategory::PetFood);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->promotionNowDepth)->toBe($worth)
        ->and($suggestion->depth)->toBe($depth)
        ->and($suggestion->alreadyMet)->toBeTrue();
})->with([
    '1+1 free on €2.99' => ['2.99', 50, 45],
    '2nd half price on €2.99' => ['4.49', 25, 20],
]);

it('floors a claimed discount without slack', function (string $now, string $claim, int $depth): void {
    $product = alertSuggestionProduct([
        ['current_price' => $now, 'claimed_regular_price' => $claim],
    ], ProductCategory::PetFood);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->depth)->toBe($depth)
        ->and($suggestion->alreadyMet)->toBeTrue();
})->with([
    '€1.49 against €1.98 is 24.7% off' => ['1.49', '1.98', 20],
    '€0.08 against €0.10 is exactly 20% off' => ['0.08', '0.10', 20],
]);

it('rounds a current promotion down to a step of 5%', function (): void {
    // 43% off now: 2.28 against a claimed 4.00, over a pet-food prior of 20%.
    $product = alertSuggestionProduct([
        ['current_price' => '2.28', 'claimed_regular_price' => '4.00'],
    ], ProductCategory::PetFood);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->promotionNowDepth)->toBe(43)
        ->and($suggestion->depth)->toBe(40)
        ->and($suggestion->depthSource)->toBe(DepthSource::PromotionNow);
});

it('cuts the target to four decimals rather than rounding it up', function (): void {
    // 3 pieces for €1.00 is €0.3333… each; 35% under that is €0.21666….
    $product = alertSuggestionProduct([
        ['current_price' => '1.00', 'pack_unit' => 'piece', 'pack_quantity' => '3.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->unitTarget)->toBe('0.2166')
        ->and($suggestion->packTarget)->toBe('0.64');
});

it('prices the target for a whole pack sold per piece', function (): void {
    $product = alertSuggestionProduct([
        ['current_price' => '4.00', 'pack_unit' => 'piece', 'pack_quantity' => '10.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->unit)->toBe('piece')
        ->and($suggestion->unitTarget)->toBe('0.26')
        ->and($suggestion->packTarget)->toBe('2.60');
});

it('leaves out a shop with a promotion label and no dates', function (): void {
    $product = alertSuggestionProduct([
        ['current_price' => '2.00', 'promotion_label' => 'Actie'],
        ['current_price' => '4.00'],
    ], ProductCategory::Pantry);

    expect(AlertSuggestion::for($product)->normalUnitPrice)->toBe('4.0000');
});

it('leaves out a cheaper shop that is out of stock', function (): void {
    $product = alertSuggestionProduct([
        ['current_price' => '2.00', 'claimed_regular_price' => '3.00', 'current_in_stock' => false],
        ['current_price' => '4.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('4.0000')
        ->and($suggestion->promotionNowHost)->toBeNull();
});

it('still counts a live bundle where "was" prices are not believed', function (): void {
    $product = alertSuggestionProduct([
        ['current_price' => '3.00', 'single_item_price' => '4.00', 'bundle_quantity' => 2, 'bundle_total_price' => '6.00', 'pack_unit' => 'piece', 'pack_quantity' => '1.00'],
    ], ProductCategory::Accessories);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('4.0000')
        ->and($suggestion->promotionNowDepth)->toBe(25);
});

it('names the shop with the deepest promotion now', function (): void {
    $product = alertSuggestionProduct([
        ['url' => 'https://ah.nl/p/1', 'current_price' => '3.00', 'claimed_regular_price' => '4.00'],
        ['url' => 'https://jumbo.com/p/1', 'current_price' => '2.00', 'single_item_price' => '4.00', 'bundle_quantity' => 2, 'bundle_total_price' => '4.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->promotionNowHost)->toBe('jumbo.com')
        ->and($suggestion->promotionNowDepth)->toBe(50);
});

it('reads a "was" price equal to the shelf price as no discount', function (): void {
    $product = alertSuggestionProduct([
        ['current_price' => '4.00', 'claimed_regular_price' => '4.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('4.0000')
        ->and($suggestion->promotionNowDepth)->toBe(0);
});

it('prefers the category set on the product over a suggested one', function (): void {
    $product = alertSuggestionProduct([['current_price' => '4.00']], ProductCategory::PetFood);
    $product->forceFill(['suggested_category' => ProductCategory::CoffeeTea])->save();

    expect(AlertSuggestion::for($product->refresh())->depth)->toBe(20);
});

it('takes the saving off the pack price, so an exact target stays exact', function (): void {
    // 40% off €1.00 for 3 is €0.60 a pack, €0.20 each, which €0.60 now meets.
    $product = alertSuggestionProduct([
        ['current_price' => '0.60', 'claimed_regular_price' => '1.00', 'pack_unit' => 'piece', 'pack_quantity' => '3.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->depth)->toBe(40)
        ->and($suggestion->unitTarget)->toBe('0.2')
        ->and($suggestion->packTarget)->toBe('0.60')
        ->and($suggestion->alreadyMet)->toBeTrue();
});

it('never steps a promotion up past what the shop charges now', function (): void {
    // €3.01 against €4.00 is 24.75% off: the 20% step, not 25%, so the target
    // (€3.20) is one today's price already meets.
    $product = alertSuggestionProduct([
        ['current_price' => '3.01', 'claimed_regular_price' => '4.00'],
    ], ProductCategory::PetFood);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->promotionNowDepth)->toBe(25)
        ->and($suggestion->depth)->toBe(20)
        ->and($suggestion->unitTarget)->toBe('3.2')
        ->and($suggestion->alreadyMet)->toBeTrue();
});

it('suggests no target the unit price column cannot hold', function (string $packPrice, float $perPack, bool $fits): void {
    expect(UnitTargetGuide::percentUnder(Numeric::str($packPrice), $perPack, 30) !== null)->toBe($fits);
})->with([
    'at the column maximum' => ['99999999.99', 0.7, true],
    'above it: €25,000 for 0.1 g' => ['25000.00', 0.0001, false],
]);
