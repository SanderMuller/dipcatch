<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

use function Pest\Livewire\livewire;

/**
 * Coffee at ah.nl and jumbo.com, dish soap at ah.nl and action.com.
 *
 * @return array{0: User, 1: Product, 2: Product}
 */
function shopFilterAccount(): array
{
    $user = User::factory()->create();
    $coffee = Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);
    $soap = Product::factory()->categorised(ProductCategory::Cleaning)->create(['user_id' => $user->id, 'title' => 'Dish soap']);

    Shop::factory()->for($coffee)->create(['url' => 'https://www.ah.nl/p/coffee']);
    Shop::factory()->for($coffee)->create(['url' => 'https://www.jumbo.com/p/coffee']);
    Shop::factory()->for($soap)->create(['url' => 'https://www.ah.nl/p/soap']);
    Shop::factory()->for($soap)->create(['url' => 'https://www.action.com/p/soap']);

    return [$user, $coffee, $soap];
}

it('filters the products to the ones tracked at a shop', function (): void {
    [$user] = shopFilterAccount();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('shop', 'jumbo.com')
        ->assertSee('Aroma Rood')
        ->assertDontSee('Dish soap');
});

it('lists every shop the account tracks at, alphabetically', function (): void {
    [$user] = shopFilterAccount();
    Shop::factory()->create(['url' => 'https://www.plus.nl/p/not-mine']);

    $this->actingAs($user);

    $component = livewire(ProductList::class);

    expect($component->viewData('shopHosts'))->toBe(['action.com', 'ah.nl', 'jumbo.com'])
        // What a click sends: a Blade directive inside a component tag is not
        // compiled, so a literal one here would set nothing.
        ->and($component->html())->toContain('wire:click="$set(\'shop\', \'ah.nl\')"');
});

it('lists only the shops with a product in the category filter', function (): void {
    [$user] = shopFilterAccount();

    $this->actingAs($user);

    expect(livewire(ProductList::class)->set('category', 'food')->viewData('shopHosts'))->toBe(['ah.nl', 'jumbo.com'])
        ->and(livewire(ProductList::class)->set('category', ProductCategory::Cleaning->value)->viewData('shopHosts'))->toBe(['action.com', 'ah.nl']);
});

it('leaves out a paused shop', function (): void {
    [$user, $coffee] = shopFilterAccount();
    Shop::factory()->for($coffee)->inactive()->create(['url' => 'https://www.lidl.nl/p/coffee']);

    $this->actingAs($user);

    expect(livewire(ProductList::class)->viewData('shopHosts'))->not->toContain('lidl.nl');
});

it('keeps the selected shop offered when the category leaves it out', function (): void {
    [$user] = shopFilterAccount();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('shop', 'action.com')
        ->set('category', 'food')
        ->assertViewHas('shopHosts', ['ah.nl', 'jumbo.com', 'action.com'])
        ->assertSee('No product matches this filter.');
});

it('writes a shop host into the shop list as a script string, so a quote in it stays text', function (): void {
    $user = User::factory()->create();
    $shop = Shop::factory()->for(Product::factory()->for($user)->create())->create(['url' => 'https://www.jumbo.com/p/1']);
    $shop->forceFill(['host' => "jumbo.com')+alert(1)+('"])->saveQuietly();
    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSeeHtml("\$set('shop', 'jumbo.com\\u0027)+alert(1)+(\\u0027')")
        ->assertDontSeeHtml("\$set('shop', 'jumbo.com')+alert(1)");
});

it('groups by the alert price only where the chosen shop has the price', function (): void {
    $user = User::factory()->create();

    foreach (['At the alert here' => ['jumbo.com', 'musclehouse.nl'], 'At the alert elsewhere' => ['musclehouse.nl', 'jumbo.com']] as $title => [$cheapHost, $dearHost]) {
        $product = Product::factory()->for($user)->create(['title' => $title, 'currency' => 'EUR']);
        $cheap = Shop::factory()->for($product)->create(['url' => "https://{$cheapHost}/p/1", 'current_price' => '1.00', 'currency' => 'EUR']);
        Shop::factory()->for($product)->create(['url' => "https://{$dearHost}/p/1", 'current_price' => '2.00', 'currency' => 'EUR']);
        // A price for the cheap shop's pack, met there only.
        $product->forceFill(['unit_price_target' => '1.50', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $cheap->id])->save();
        $product->refresh()->recomputeCheapestShop();
    }

    $this->actingAs($user);

    livewire(ProductList::class)
        // Both at their alert price, so no other group and no line.
        ->assertDontSeeHtml('data-test="drop-divider"')
        ->set('shop', 'jumbo.com')
        ->assertSeeHtmlInOrder(['data-test="at-alert-divider"', 'At the alert here', 'data-test="drop-divider"', 'At the alert elsewhere']);
});

it('counts a pack target at the shop it is pinned to, not at a cheaper pack elsewhere', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Barebells 12-pack', 'currency' => 'EUR']);
    // One bar at Jumbo is the lowest price; the 12-pack at MuscleHouse is the pack the target is for.
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '1.00', 'currency' => 'EUR']);
    $pack = Shop::factory()->for($product)->create(['url' => 'https://musclehouse.nl/p/1', 'current_price' => '10.00', 'currency' => 'EUR']);
    $product->forceFill(['unit_price_target' => '11.00', 'unit_price_target_unit' => 'pack', 'unit_price_target_shop_id' => $pack->id])->save();
    $other = Product::factory()->for($user)->create(['title' => 'Something else', 'currency' => 'EUR']);
    Shop::factory()->for($other)->create(['url' => 'https://jumbo.com/p/2', 'current_price' => '3.00', 'currency' => 'EUR']);
    Shop::factory()->for($other)->create(['url' => 'https://musclehouse.nl/p/2', 'current_price' => '3.00', 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();
    $other->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('shop', 'musclehouse.nl')
        ->assertSeeHtmlInOrder(['data-test="at-alert-divider"', 'Barebells 12-pack']);

    livewire(ProductList::class)
        ->set('shop', 'jumbo.com')
        ->assertDontSeeHtml('data-test="at-alert-divider"')
        ->set('discounted', true)
        ->assertDontSee('Barebells 12-pack');

    livewire(ProductList::class)
        ->set('shop', 'musclehouse.nl')
        ->set('discounted', true)
        ->assertSee('Barebells 12-pack');
});

it('groups a target per kilo under the shop with the best value only', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Per kilo', 'currency' => 'EUR', 'unit_price_target' => '6.00', 'unit_price_target_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://lidl.nl/p/1', 'current_price' => '1.99', 'currency' => 'EUR', 'pack_quantity' => '370.00', 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1', 'current_price' => '2.49', 'currency' => 'EUR', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
    $product->refresh()->recomputeCheapestShop();
    // A product at no alert at either shop, so the groups get a line.
    $other = Product::factory()->for($user)->create(['title' => 'No alert', 'currency' => 'EUR']);
    Shop::factory()->for($other)->create(['url' => 'https://lidl.nl/p/2', 'current_price' => '3.00', 'currency' => 'EUR']);
    Shop::factory()->for($other)->create(['url' => 'https://ah.nl/p/2', 'current_price' => '3.00', 'currency' => 'EUR']);
    $other->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    // €1.99 for 370 g is €5.38 a kilo at Lidl, the best value.
    livewire(ProductList::class)
        ->set('shop', 'lidl.nl')
        ->assertSeeHtmlInOrder(['data-test="at-alert-divider"', 'Per kilo']);

    livewire(ProductList::class)
        ->set('shop', 'ah.nl')
        ->assertDontSeeHtml('data-test="at-alert-divider"');
});
