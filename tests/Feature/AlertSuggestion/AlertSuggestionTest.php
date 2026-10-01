<?php declare(strict_types=1);

use App\Enums\DepthSource;
use App\Enums\ProductCategory;
use App\Enums\PromotionDepthBand;
use App\Models\Product;
use App\Models\Shop;
use App\Support\AlertSuggestion\AlertSuggestion;
use Carbon\CarbonImmutable;

/**
 * A product with the given shops, each a list of shop columns. The shops sell
 * 1 kg unless a row says otherwise.
 *
 * @param  list<array<string, mixed>>  $shops
 */
function suggestionProduct(array $shops, ?ProductCategory $category = null): Product
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
    $product = suggestionProduct([
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
    $product = suggestionProduct([
        ['current_price' => '3.00', 'single_item_price' => '4.00', 'bundle_quantity' => 2, 'bundle_total_price' => '6.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('4.0000')
        ->and($suggestion->promotionNowDepth)->toBe(25)
        ->and($suggestion->depthSource)->toBe(DepthSource::Category)
        ->and($suggestion->unitTarget)->toBe('2.6');
});

it('bases a product sold in several sizes on its best-value pack', function (): void {
    $product = suggestionProduct([
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
    $product = suggestionProduct([
        ['current_price' => $now, 'single_item_price' => '4.00', 'bundle_quantity' => $quantity, 'bundle_total_price' => $total],
    ], ProductCategory::PetFood);

    expect(AlertSuggestion::for($product)->depth)->toBe($depth);
})->with([
    '1+1 free, 50%' => ['2.00', 2, '4.00', 50],
    '2+3 free at 60%, the cap' => ['1.60', 5, '8.00', 60],
    '1+2 free at 66% stops at the cap' => ['1.33', 3, '4.00', 60],
]);

it('caps alcohol at the 25% the law allows', function (): void {
    $product = suggestionProduct([
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
    $product = suggestionProduct([
        ['current_price' => '70.00', 'claimed_regular_price' => '100.00', 'pack_unit' => 'piece', 'pack_quantity' => '1.00'],
    ], ProductCategory::SmallAppliances);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->unitTarget)->toBeNull()
        ->and($suggestion->normalUnitPrice)->toBeNull();
});

it('leaves out a shop whose promotion hides its normal price', function (): void {
    // Aldi-style: a running window, no "was" price. The other shop sets the basis.
    $product = suggestionProduct([
        ['current_price' => '2.00', 'promotion_starts_at' => CarbonImmutable::now()->subDay(), 'promotion_ends_at' => CarbonImmutable::now()->addDays(3)],
        ['current_price' => '4.00'],
    ], ProductCategory::Pantry);

    expect(AlertSuggestion::for($product)->normalUnitPrice)->toBe('4.0000');
});

it('reads an announced bonus that has not started as the regular price', function (): void {
    $product = suggestionProduct([
        ['current_price' => '4.00', 'promotion_starts_at' => CarbonImmutable::now()->addDay(), 'promotion_ends_at' => CarbonImmutable::now()->addDays(7)],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('4.0000')
        ->and($suggestion->promotionNowDepth)->toBe(0);
});

it('checks a stored "was" price against today\'s shelf price', function (): void {
    // A €4.00 claim kept from an earlier reading, while the shelf is €5.00 now.
    $product = suggestionProduct([
        ['current_price' => '5.00', 'claimed_regular_price' => '4.00'],
    ], ProductCategory::Pantry);

    $suggestion = AlertSuggestion::for($product);

    expect($suggestion->normalUnitPrice)->toBe('5.0000')
        ->and($suggestion->promotionNowDepth)->toBe(0);
});

it('lets a confident Jev band override the category', function (): void {
    $product = suggestionProduct([['current_price' => '4.00']], ProductCategory::PetFood);

    $suggestion = AlertSuggestion::for($product, PromotionDepthBand::HalfOrMore);

    expect($suggestion->depth)->toBe(50)
        ->and($suggestion->depthSource)->toBe(DepthSource::Jev);
});

it('suggests no target for a fixed-price product, whatever a shop shows', function (): void {
    $product = suggestionProduct([
        ['current_price' => '3.00', 'claimed_regular_price' => '4.00', 'pack_unit' => 'piece', 'pack_quantity' => '1.00'],
    ], ProductCategory::Books);

    $suggestion = AlertSuggestion::for($product, PromotionDepthBand::Fixed);

    expect($suggestion->unitTarget)->toBeNull()
        ->and($suggestion->band)->toBe(PromotionDepthBand::Fixed);
});

it('suggests no target without a category, a band or a promotion', function (): void {
    $suggestion = AlertSuggestion::for(suggestionProduct([['current_price' => '4.00']]));

    expect($suggestion->unitTarget)->toBeNull()
        ->and($suggestion->depthSource)->toBe(DepthSource::None)
        ->and($suggestion->normalUnitPrice)->toBe('4.0000');
});

it('suggests no target when no shop states a pack size', function (): void {
    $suggestion = AlertSuggestion::for(suggestionProduct([['current_price' => '4.00', 'pack_quantity' => null, 'pack_unit' => null]], ProductCategory::Pantry));

    expect($suggestion->unitTarget)->toBeNull()
        ->and($suggestion->normalUnitPrice)->toBeNull();
});

it('suggests no target below the smallest one the column keeps', function (): void {
    // €0.01 for 1000 pieces is a thousandth of a cent each.
    $product = suggestionProduct([['current_price' => '0.01', 'pack_unit' => 'piece', 'pack_quantity' => '1000.00']], ProductCategory::Pantry);

    expect(AlertSuggestion::for($product)->unitTarget)->toBeNull();
});

it('suggests no target for a product with no shop', function (): void {
    $product = Product::factory()->create(['category' => ProductCategory::Pantry]);

    expect(AlertSuggestion::for($product)->unitTarget)->toBeNull();
});

it('falls back to the suggested category', function (): void {
    $product = suggestionProduct([['current_price' => '4.00']]);
    $product->forceFill(['suggested_category' => ProductCategory::CoffeeTea])->save();

    $suggestion = AlertSuggestion::for($product->refresh());

    expect($suggestion->depth)->toBe(40)
        ->and($suggestion->category)->toBe(ProductCategory::CoffeeTea);
});

it('never bases the target on a shop that only inherits its pack size', function (): void {
    // The unsized €6 shop can borrow the 1 kg size to compare, but it can never
    // win best value, so the unit target could never fire through it.
    $product = suggestionProduct([
        ['current_price' => '10.00'],
        ['current_price' => '6.00', 'pack_quantity' => null, 'pack_unit' => null],
    ], ProductCategory::Pantry);

    $unsized = $product->shops()->where('current_price', '6.00')->sole();

    // The premise: it does borrow a size.
    expect($product->comparablePacks()->for($unsized)?->size)->not->toBeNull()
        ->and(AlertSuggestion::for($product)->normalUnitPrice)->toBe('10.0000');
});
