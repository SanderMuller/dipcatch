<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Livewire\livewire;

/**
 * @return array{0: User, 1: Product}
 */
function overviewProduct(): array
{
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'title' => "Lay's Naturel",
        'drop_threshold_pct' => '25.00',
        'drop_threshold_abs' => '1.60',
    ]);

    $ah = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi8/x', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    $lidl = Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/lay-s/p8', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);

    // Both prices are promotions ending the same Sunday, as they were on
    // the day this layout was designed.
    foreach ([$ah, $lidl] as $shop) {
        $shop->forceFill(['promotion_ends_at' => CarbonImmutable::parse('2036-09-06 21:59:59')])->save();
    }
    $product->forceFill(['cheapest_shop_id' => $ah->id, 'cheapest_price' => '1.69'])->save();

    return [$user, $product->refresh()];
}

test('the overview answers what it costs, where, and for how long', function (): void {
    [$user, $product] = overviewProduct();
    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->getKey()])
        ->assertSeeText('Cheapest now')
        ->assertSeeText('€1.69')
        // Both prices are quoted the same way, so they can be compared.
        ->assertSeeText('€8.45 /kg · until 6 Sep')
        ->assertSeeText('Best value')
        ->assertSeeText('€5.38 /kg')
        ->assertSeeText('€1.99 · until 6 Sep')
        ->assertSeeText('ah.nl')
        ->assertSeeText('lidl.nl');
});

test('the overview says what the product is and how it is tracked', function (): void {
    [$user, $product] = overviewProduct();
    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->getKey()])
        ->assertSeeText("Lay's Naturel")
        ->assertSeeText('Tracked at')
        ->assertSeeText('2 shops')
        ->assertSeeText('Tracking')
        ->assertSeeText('Active')
        ->assertSeeText('Alerts below')
        ->assertSeeText('25.00 % · €1.60');
});

test('the price history sits on the overview, not below the shops', function (): void {
    [$user, $product] = overviewProduct();
    $this->actingAs($user);

    // The chart is part of the page schema now, so it renders with the
    // prices rather than after the relation manager.
    livewire(ViewProduct::class, ['record' => $product->getKey()])
        ->assertSeeText('Cheapest price history');
});
