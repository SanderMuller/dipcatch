<?php declare(strict_types=1);

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Enums\PackExclusion;
use App\Enums\PackProvenance;
use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Livewire\Products\EditProduct;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\AddShopTool;
use App\Mcp\Tools\SetThresholdTool;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use App\Notifications\UnitPriceTargetNotification;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\AlertRules;
use App\Support\HeadlinePrice;
use App\Support\UnitTargetConversion;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

/**
 * A shop with a primary size and, optionally, the same pack in a second unit.
 *
 * @param  array<string, mixed>  $extra
 */
function pairedShop(Product $product, string $host, string $price, string $quantity, string $unit, ?string $altQuantity = null, ?string $altUnit = null, array $extra = []): Shop
{
    $shop = Shop::factory()->for($product)->create(['url' => "https://{$host}/p/" . fake()->uuid()]);

    $shop->forceFill([
        'currency' => 'EUR',
        'current_price' => $price,
        'current_in_stock' => true,
        'pack_quantity' => $quantity,
        'pack_unit' => $unit,
        'alt_pack_quantity' => $altQuantity,
        'alt_pack_unit' => $altUnit,
        ...$extra,
    ])->save();

    return $shop;
}

/**
 * The Iglo fish fingers field as it was read on 2026-10-06: AH and Poiesz
 * count pieces and state grams second, Jumbo and Dirk weigh and count in the
 * title, and the XXL box only weighs.
 *
 * @param  array<model-property<Product>, mixed>  $productAttributes
 */
function igloField(array $productAttributes = []): Product
{
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => 'Iglo Vissticks', ...$productAttributes]);

    pairedShop($product, 'ah.nl', '4.99', '20.00', 'piece', '560.00', 'g');
    pairedShop($product, 'ah.nl', '6.59', '30.00', 'piece', '840.00', 'g');
    pairedShop($product, 'ah.nl', '3.75', '15.00', 'piece', '420.00', 'g');
    pairedShop($product, 'webwinkel.poiesz-supermarkten.nl', '3.75', '20.00', 'piece', '560.00', 'g');
    pairedShop($product, 'jumbo.com', '3.69', '420.00', 'g', '15.00', 'piece');
    pairedShop($product, 'dirk.nl', '3.99', '560.00', 'g', '20.00', 'piece');
    pairedShop($product, 'jumbo.com', '6.15', '840.00', 'g');

    return $product->refresh();
}

it('compares the Iglo field per kilo, with every shop in it and Poiesz the best value', function (): void {
    $product = igloField();
    $packs = $product->comparablePacks();

    expect($packs->unit())->toBe('g')
        ->and($product->shops->every(fn (Shop $shop): bool => $packs->for($shop)?->provenance === PackProvenance::Stated))->toBeTrue()
        ->and($product->bestValueShop()?->host)->toBe('webwinkel.poiesz-supermarkten.nl');

    $product->recomputeCheapestShop();

    // €3.75 for 560 g: stored on the size it was compared by, not its 20 pieces.
    expect($product)
        ->best_value_pack_unit->toBe('g')
        ->best_value_pack_quantity->toBe('560.00');
});

it('keeps tablets per piece when only one shop also states their weight', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    pairedShop($product, 'ah.nl', '9.99', '18.00', 'piece', '270.00', 'g');
    pairedShop($product, 'jumbo.com', '10.49', '18.00', 'piece');
    pairedShop($product, 'kruidvat.nl', '19.99', '40.00', 'piece');

    expect($product->refresh()->comparablePacks()->unit())->toBe('piece');
});

it('resolves a product with no second sizes exactly as before', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    pairedShop($product, 'a.nl', '2.00', '500.00', 'g');
    pairedShop($product, 'b.nl', '2.10', '500.00', 'g');
    pairedShop($product, 'c.nl', '2.00', '10.00', 'piece');

    $packs = $product->refresh()->comparablePacks();

    expect($packs->unit())->toBe('g')
        ->and($packs->for($product->shops()->where('host', 'c.nl')->firstOrFail())?->exclusion)->toBe(PackExclusion::SoldByThePiece);
});

it('keeps the unit it compares in when shops cover both units equally', function (string $current): void {
    $product = Product::factory()->create(['currency' => 'EUR', 'best_value_pack_unit' => $current]);
    pairedShop($product, 'a.nl', '4.99', '20.00', 'piece', '560.00', 'g');
    pairedShop($product, 'b.nl', '3.99', '560.00', 'g', '20.00', 'piece');

    expect($product->refresh()->comparablePacks()->unit())->toBe($current);
})->with(['g', 'piece']);

it('counts only second sizes that hold, so implausible ones do not win the vote', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    // Three piece shops whose "grams" put them at a few cents a kilo.
    pairedShop($product, 'a.nl', '5.00', '20.00', 'piece', '56000.00', 'g');
    pairedShop($product, 'b.nl', '5.10', '20.00', 'piece', '56000.00', 'g');
    pairedShop($product, 'c.nl', '5.20', '20.00', 'piece', '56000.00', 'g');
    $grams = pairedShop($product, 'd.nl', '3.99', '560.00', 'g');

    $packs = $product->refresh()->comparablePacks();

    expect($packs->unit())->toBe('piece')
        ->and($packs->altInDoubt($product->shops()->where('host', 'a.nl')->firstOrFail()))->toBeTrue()
        ->and($packs->for($grams)?->exclusion)->toBe(PackExclusion::UnitDoesNotConvert);
});

it('excludes an implausible second size, unless Jev confirmed it, and always when Jev rejected it', function (?bool $answer, ?PackProvenance $provenance, ?PackExclusion $exclusion): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    pairedShop($product, 'a.nl', '3.99', '560.00', 'g');
    pairedShop($product, 'b.nl', '4.10', '560.00', 'g');
    pairedShop($product, 'c.nl', '3.80', '560.00', 'g');
    // €1.00 for 560 g is far below the field's €7/kg.
    $odd = pairedShop($product, 'd.nl', '1.00', '20.00', 'piece', '560.00', 'g', ['alt_pack_confirmed' => $answer]);

    $pack = $product->refresh()->comparablePacks()->for($odd);

    expect($pack?->provenance)->toBe($provenance)
        ->and($pack?->exclusion)->toBe($exclusion);
})->with([
    'not asked' => [null, null, PackExclusion::SizeImplausible],
    'confirmed' => [true, PackProvenance::Stated, null],
    // Rejected: the shop has no size in grams at all.
    'rejected' => [false, null, PackExclusion::SoldByThePiece],
]);

it('keeps a rejected second size out even inside the band', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    pairedShop($product, 'a.nl', '3.99', '560.00', 'g');
    $rejected = pairedShop($product, 'b.nl', '4.10', '20.00', 'piece', '560.00', 'g', ['alt_pack_confirmed' => false]);

    expect($product->refresh()->comparablePacks()->for($rejected)?->isExcluded())->toBeTrue();
});

it('converts a per-piece target into the per-kilo comparison, without touching what the owner set', function (): void {
    $product = igloField(['unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece']);

    $product->recomputeCheapestShop();

    expect($product)
        ->unit_price_target->toBe('0.2500')
        ->unit_price_target_unit->toBe('piece')
        ->unit_price_target_effective->toBe('8.9286');

    // Back to pieces, once the counting shops stop stating grams: the
    // owner's own number, with no drift from the trip.
    $product->shops()->where('pack_unit', 'piece')->update(['alt_pack_quantity' => null, 'alt_pack_unit' => null]);
    $product->refresh()->recomputeCheapestShop();

    expect($product->comparablePacks()->unit())->toBe('piece')
        ->and($product->unit_price_target_effective)->toBe('0.2500');
});

it('stamps a target with no unit in the unit it was read in, not the one a recompute moves to', function (): void {
    // Compared per piece until now; this recompute moves it to grams.
    $product = igloField(['unit_price_target' => '0.25', 'best_value_pack_unit' => 'piece']);

    $product->recomputeCheapestShop();

    expect($product)
        ->best_value_pack_unit->toBe('g')
        ->unit_price_target_unit->toBe('piece')
        ->unit_price_target_effective->toBe('8.9286');
});

it('counts a latch that converts out of the storage range as no latch', function (): void {
    $product = igloField(['unit_price_notified' => '99999999.0000', 'unit_price_notified_unit' => 'piece']);

    expect(DetectUnitPriceTarget::latchIn($product, 'g', $product->comparablePacks()))->toBeNull();
});

it('reads a latch armed per piece in the per-kilo unit, so an alert already sent is not sent again', function (): void {
    Notification::fake();
    // Poiesz at €3.75 for 20 pieces was €0.1875 a piece when the alert went out.
    $product = igloField([
        'unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece',
        'unit_price_notified' => '0.1875', 'unit_price_notified_unit' => 'piece',
    ]);
    $product->recomputeCheapestShop();

    expect(DetectUnitPriceTarget::latchIn($product, 'g', $product->comparablePacks()))->toEqualWithDelta(6.6964, 0.0001);

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertNothingSent();
});

it('suspends the target when the shops disagree on what a piece weighs', function (): void {
    $product = igloField(['unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece']);
    // A shop whose sticks weigh twice as much, and nobody has asked Jev.
    pairedShop($product, 'plus.nl', '4.49', '10.00', 'piece', '560.00', 'g');

    $product->refresh()->recomputeCheapestShop();
    $packs = $product->comparablePacks();

    expect($packs->itemSizeBetween('piece', 'g'))->toBeNull()
        ->and($packs->itemSizeOutliers('piece', 'g'))->toHaveCount(1)
        ->and($product->isUnitTargetSuspended())->toBeTrue();
});

it('converts again once Jev rejects the shop that disagreed', function (): void {
    $product = igloField(['unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece']);
    // Its sticks weigh twice as much, and Jev said its count is wrong.
    pairedShop($product, 'plus.nl', '4.49', '560.00', 'g', '10.00', 'piece', ['alt_pack_confirmed' => false]);

    $product->refresh()->recomputeCheapestShop();

    expect($product->comparablePacks()->unit())->toBe('g')
        ->and($product->unit_price_target_effective)->toBe('8.9286');
});

it('blocks a conversion on a confirmed outlier, a pair in doubt, or a weight against a volume', function (): void {
    $product = igloField();
    pairedShop($product, 'plus.nl', '4.49', '10.00', 'piece', '560.00', 'g', ['alt_pack_confirmed' => true]);
    $packs = $product->refresh()->comparablePacks();

    expect($packs->itemSizeBetween('piece', 'g'))->toBeNull()
        ->and($packs->itemSizeOutliers('piece', 'g'))->toBeEmpty()
        ->and($packs->itemSizeBetween('g', 'ml'))->toBeNull();
});

it('treats a conversion out of the storage range as no conversion', function (): void {
    $product = igloField();
    $packs = $product->comparablePacks();

    expect(UnitTargetConversion::convert('99999999', 'piece', 'g', $packs))->toBeNull()
        ->and(UnitTargetConversion::convert('0.0001', 'g', 'piece', $packs))->toBeNull()
        ->and(UnitTargetConversion::convert('0.25', 'piece', 'g', $packs))->toBe('8.9286');
});

it('names the same shops outside the comparison to an MCP client as the page does', function (): void {
    $product = igloField();
    $product->recomputeCheapestShop();
    // A shop that only counts, with nothing stated by weight. Pieces now
    // cover as many shops as grams, and the product stays per kilo.
    pairedShop($product, 'plus.nl', '4.49', '20.00', 'piece');

    DipCatchServer::actingAs($product->user()->firstOrFail())
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 8.0])
        ->assertOk()
        ->assertSee('7 of 8 shops report grams')
        ->assertSee('plus.nl');
});

it('picks the same unit and winner on the public page as on the owner page', function (): void {
    $product = igloField(['share_slug' => str_repeat('i', 32)]);
    $product->recomputeCheapestShop();

    $this->get('/p/' . str_repeat('i', 32))->assertOk()->assertSeeHtml(HeadlinePrice::of($product->refresh())->text())
        ->assertSee('/kg');
});

it('sends no drop alert when a shop joins the comparison on its second size', function (): void {
    Notification::fake();
    $product = Product::factory()->create(['currency' => 'EUR', 'drop_threshold_pct' => 10]);
    $a = pairedShop($product, 'a.nl', '10.00', '500.00', 'g');
    $b = pairedShop($product, 'b.nl', '10.20', '500.00', 'g');
    $joiner = pairedShop($product, 'c.nl', '7.00', '20.00', 'piece');

    foreach ([$a, $b, $joiner] as $shop) {
        PriceCheck::factory()->for($shop)->create(['price' => $shop->current_price, 'status' => ScrapeStatus::Ok, 'in_stock' => true, 'checked_at' => now()->subDays(3)]);
    }

    $product->refresh()->recomputeCheapestShop();

    $joiner->forceFill(['alt_pack_quantity' => '500.00', 'alt_pack_unit' => 'g', 'alt_pack_since' => now()])->save();

    // That reading and the next two, all at the same price.
    foreach ([0, 1, 2] as $hours) {
        $this->travel($hours)->hours();
        $next = PriceCheck::factory()->for($joiner)->create(['price' => '7.00', 'status' => ScrapeStatus::Ok, 'in_stock' => true]);
        $product->refresh()->recomputeCheapestShop($next->id);
    }

    expect($product->refresh()->best_value_shop_id)->toBe((string) $joiner->id)
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(0);
});

it('still reports a real fall at a shop that gains a second size it does not compete on', function (): void {
    Notification::fake();
    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR', 'drop_threshold_pct' => 10]);
    $shop = pairedShop($product, 'jumbo.com', '4.19', '500.00', 'g');
    PriceCheck::factory()->for($shop)->create(['price' => '4.19', 'status' => ScrapeStatus::Ok, 'in_stock' => true]);
    $product->refresh()->recomputeCheapestShop();

    $shop->forceFill(['current_price' => '2.75', 'alt_pack_quantity' => '20.00', 'alt_pack_unit' => 'piece', 'alt_pack_since' => now()])->save();
    $later = PriceCheck::factory()->for($shop)->create(['price' => '2.75', 'status' => ScrapeStatus::Ok, 'in_stock' => true]);
    $product->refresh()->recomputeCheapestShop($later->id);

    expect(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1);
});

it('gives no pack warning for a page that sells what a shop states as its second size', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/9' => Http::response(jsonLdPage('3.99', 'EUR', 'Iglo Vissticks 560 g'), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    pairedShop($product, 'ah.nl', '4.99', '20.00', 'piece', '560.00', 'g');

    DipCatchServer::actingAs($user)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => 'https://shop.example.com/p/9'])
        ->assertOk()
        ->assertDontSee('different pack');
});

it('gives no pack warning for a page whose own second size is the pack the shops sell', function (): void {
    Http::fake([
        'https://webwinkel.poiesz-supermarkten.nl/robots.txt' => Http::response('', 404),
        'https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/278550' => Http::response(poieszPage(price: '3.75', packageDescription: '20.00 Stuks', volumeCe: 560.0, unitId: 'GR'), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    pairedShop($product, 'dirk.nl', '3.99', '560.00', 'g');

    DipCatchServer::actingAs($user)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => 'https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/278550'])
        ->assertOk()
        ->assertSee('3.75')
        ->assertDontSee('different pack');
});

it('fires a converted target, and records and sends it in the unit it was compared in', function (): void {
    Notification::fake();
    $product = igloField(['unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece']);
    $product->recomputeCheapestShop();

    app(DetectUnitPriceTarget::class)($product);

    $user = $product->user()->firstOrFail();
    Notification::assertSentTo($user, UnitPriceTargetNotification::class, function (UnitPriceTargetNotification $notification) use ($product, $user): bool {
        // Delivered after the product went back to pieces, it still states
        // the per-kilo target the alert was decided on.
        $product->shops()->where('pack_unit', 'piece')->update(['alt_pack_quantity' => null, 'alt_pack_unit' => null]);
        $product->refresh()->recomputeCheapestShop();

        return $notification->toDatabase($user)['unit_price_target'] === '8.9286';
    });

    expect(TargetPriceEvent::query()->sole())
        ->target->toBe('8.9286')
        ->comparison_unit->toBe('g')
        ->pack_quantity->toBe('560.00');
});

it('agrees in SQL and in PHP on a converted target', function (string $target, bool $expected): void {
    $product = igloField(['unit_price_target' => $target, 'unit_price_target_unit' => 'piece']);
    $product->recomputeCheapestShop();

    // Poiesz is €6.6964 a kilo: 0.25 a piece is €8.93 a kilo, 0.18 is €6.43.
    expect($product->isAtTarget())->toBe($expected)
        ->and(Product::query()->atTarget()->whereKey($product->id)->exists())->toBe($expected);
})->with([
    'met' => ['0.25', true],
    'not met' => ['0.18', false],
]);

it('converts with the median item size, and counts a shop just inside the band', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    // 27, 28, 28.5 and 29.3 g a piece: the lower middle is 28, and 29.3 is
    // 4.6% off it.
    pairedShop($product, 'a.nl', '4.00', '20.00', 'piece', '540.00', 'g');
    pairedShop($product, 'b.nl', '4.10', '20.00', 'piece', '560.00', 'g');
    pairedShop($product, 'c.nl', '4.20', '20.00', 'piece', '570.00', 'g');
    pairedShop($product, 'd.nl', '4.30', '20.00', 'piece', '586.00', 'g');

    expect($product->refresh()->comparablePacks()->itemSizeBetween('piece', 'g'))->toBe(28.0);
});

it('counts a shop exactly 5% under the factor', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    // 26.6, 28 and 28 g a piece: 26.6 is 5% under the median.
    pairedShop($product, 'a.nl', '4.00', '20.00', 'piece', '532.00', 'g');
    pairedShop($product, 'b.nl', '4.10', '20.00', 'piece', '560.00', 'g');
    pairedShop($product, 'c.nl', '4.20', '20.00', 'piece', '560.00', 'g');

    expect($product->refresh()->comparablePacks()->itemSizeBetween('piece', 'g'))->toBe(28.0);
});

it('treats a shop just outside the band as an outlier', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    pairedShop($product, 'a.nl', '4.00', '20.00', 'piece', '560.00', 'g');
    pairedShop($product, 'b.nl', '4.10', '20.00', 'piece', '560.00', 'g');
    // 29.5 g a piece is 5.4% off 28.
    $outlier = pairedShop($product, 'c.nl', '4.20', '20.00', 'piece', '590.00', 'g');

    $packs = $product->refresh()->comparablePacks();

    expect($packs->itemSizeBetween('piece', 'g'))->toBeNull()
        ->and($packs->itemSizeOutliers('piece', 'g'))->toBe([(string) $outlier->id]);
});

it('blocks a conversion on a pair in doubt until Jev confirms it', function (): void {
    $product = igloField(['unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece']);
    $product->recomputeCheapestShop();
    // 28 g a piece like every other shop, but €1.00 for 560 g looks wrong.
    $doubtful = pairedShop($product, 'plus.nl', '1.00', '20.00', 'piece', '560.00', 'g');

    $product->refresh()->recomputeCheapestShop();
    expect($product->comparablePacks()->itemSizeBetween('piece', 'g'))->toBeNull()
        ->and($product->isUnitTargetSuspended())->toBeTrue();

    $doubtful->forceFill(['alt_pack_confirmed' => true])->save();
    $product->refresh()->recomputeCheapestShop();

    expect($product->unit_price_target_effective)->toBe('8.9286');
});

it('stamps an old target with the unit it was last compared in when backfilled', function (): void {
    $product = igloField(['unit_price_target' => '0.25', 'best_value_pack_unit' => 'piece']);

    $this->artisan('dipcatch:stamp-target-units')->assertSuccessful();

    expect($product->refresh())
        ->unit_price_target_unit->toBe('piece')
        ->unit_price_target_effective->toBe('8.9286');
});

it('settles a tie on the unit most shops lead with when no shop states a second size, whatever it compared in', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR', 'best_value_pack_unit' => 'piece']);
    pairedShop($product, 'a.nl', '2.00', '500.00', 'g');
    pairedShop($product, 'b.nl', '2.00', '10.00', 'piece');

    // As before this change: alphabetical on an even split.
    expect($product->refresh()->comparablePacks()->unit())->toBe('g');
});

it('settles a tie on the unit most shops lead with when it had no unit yet', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    pairedShop($product, 'a.nl', '4.99', '20.00', 'piece', '560.00', 'g');
    pairedShop($product, 'b.nl', '4.89', '20.00', 'piece', '560.00', 'g');
    pairedShop($product, 'c.nl', '3.99', '560.00', 'g', '20.00', 'piece');

    expect($product->refresh()->comparablePacks()->unit())->toBe('piece');
});

it('names a paused target in its own unit among the alert rules, with no pack line', function (): void {
    $product = igloField(['unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece']);
    pairedShop($product, 'plus.nl', '4.49', '10.00', 'piece', '560.00', 'g');
    $product->refresh()->recomputeCheapestShop();

    $paused = array_values(array_filter(AlertRules::of($product), fn (array $rule): bool => str_contains($rule['value'], 'paused')));

    expect($paused)->toHaveCount(1)
        ->and($paused[0]['value'])->toContain('/piece')
        ->and($paused[0])->not->toHaveKey('pack');
});

it('loads a converted target into the editor in today\'s unit, and keeps the owner\'s own on an untouched save', function (): void {
    $product = igloField(['unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece']);
    $product->recomputeCheapestShop();
    $this->actingAs($product->user()->firstOrFail());

    livewire(EditProduct::class, ['product' => $product])
        ->assertSet('unitPriceTarget', '8.9286')
        ->assertSet('unitPriceTargetUnit', 'g')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh())
        ->unit_price_target->toBe('0.2500')
        ->unit_price_target_unit->toBe('piece');
});

it('still warns about a page whose pack matches neither of a shop\'s two sizes', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/10' => Http::response(jsonLdPage('6.15', 'EUR', 'Iglo Vissticks 840 g'), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    pairedShop($product, 'ah.nl', '4.99', '20.00', 'piece', '560.00', 'g');

    DipCatchServer::actingAs($user)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => 'https://shop.example.com/p/10'])
        ->assertOk()
        ->assertSee('different pack');
});

it('sends no drop alert when a second size the guard kept out is admitted once its price moves', function (): void {
    Notification::fake();
    Http::preventStrayRequests();
    $product = Product::factory()->create(['currency' => 'EUR', 'drop_threshold_pct' => 10]);
    $a = pairedShop($product, 'a.nl', '10.00', '500.00', 'g');
    $b = pairedShop($product, 'b.nl', '10.20', '500.00', 'g');
    $ah = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/producten/product/wi191096/iglo-vissticks', 'adapter_key' => 'checkjebon', 'current_price' => '1.00']);

    foreach ([$a, $b] as $shop) {
        PriceCheck::factory()->for($shop)->create(['price' => $shop->current_price, 'status' => ScrapeStatus::Ok, 'in_stock' => true, 'checked_at' => now()->subDays(3)]);
    }

    $read = function (string $price) use ($ah): void {
        // A fresh fake per read: stubs stack, and the first one would answer.
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(ahApiProductFakes(currentPrice: $price, isBonus: false, title: 'Iglo Vissticks', salesUnitSize: '20 stuks', netContent: [[20.0, 'st'], [500.0, 'g']]));
        new CheckShopPrice($ah->refresh())->handle(
            app(ShopFetcher::class),
            app(AdapterResolver::class),
            app(CheckjebonSource::class),
            app(AhApiSource::class),
        );
    };

    // €1.00 for 500 g is far off €20 a kilo: kept out, read after read.
    foreach ([1, 2, 3] as $hours) {
        $this->travel(1)->hours();
        $read('1.00');
    }

    // Then €7.00, €14 a kilo: admitted, and the best value. It never fell.
    $this->travel(1)->hours();
    $read('7.00');

    expect($product->refresh()->best_value_shop_id)->toBe((string) $ah->id)
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(0);
});
