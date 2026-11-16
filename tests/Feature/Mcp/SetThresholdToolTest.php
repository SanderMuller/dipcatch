<?php declare(strict_types=1);

use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\SetThresholdTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

/**
 * One target per product through MCP: a price per unit, a percentage under
 * today's best, or the price of one shop's pack.
 *
 * @return array{User, Product, Shop, Shop}
 */
function thresholdProduct(bool $sized = true): array
{
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    $big = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/12', 'currency' => 'EUR', 'current_price' => '21.00',
        ...($sized ? ['pack_quantity' => '660.00', 'pack_unit' => 'g'] : []),
    ]);
    $bar = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/1', 'currency' => 'EUR', 'current_price' => '2.99', 'created_at' => now()->addMinute(),
        ...($sized ? ['pack_quantity' => '55.00', 'pack_unit' => 'g'] : []),
    ]);

    return [$user, $product->refresh(), $big, $bar];
}

it('stores a price per unit in the comparison unit', function (): void {
    [$user, $product] = thresholdProduct();

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 34.55])
        ->assertOk();

    expect($product->fresh()?->unit_price_target)->toBe('34.5500')
        ->and($product->fresh()?->unit_price_target_unit)->toBe('g')
        ->and($product->fresh()?->unit_price_target_shop_id)->toBeNull();
});

it('stores a target a percentage under today\'s best', function (): void {
    [$user, $product] = thresholdProduct();

    // The best value is €21.00 for 660 g, €31.8181… a kilo; 10% under it is €28.6363.
    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'percent_under_best' => 10])
        ->assertOk();

    expect($product->fresh()?->unit_price_target)->toBe('28.6363')
        ->and($product->fresh()?->unit_price_target_unit)->toBe('g');
});

it('turns a price for a sized pack into a price per unit', function (): void {
    [$user, $product, $big] = thresholdProduct();

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'pack_price' => 22.80, 'shop_id' => (string) $big->id])
        ->assertOk();

    expect($product->fresh()?->unit_price_target)->toBe('34.5454')
        ->and($product->fresh()?->unit_price_target_unit)->toBe('g');
});

it('pins a price for a pack to its shop while no shop has a size', function (): void {
    [$user, $product, $big] = thresholdProduct(sized: false);

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'pack_price' => 22.80, 'shop_id' => (string) $big->id])
        ->assertOk()
        ->assertSee('Compared with the price at ah.nl only');

    $fresh = $product->fresh();
    expect($fresh?->unit_price_target)->toBe('22.8000')
        ->and($fresh?->unit_price_target_unit)->toBe('pack')
        ->and($fresh?->unit_price_target_shop_id)->toBe($big->id)
        ->and($fresh?->isAtTarget())->toBeTrue();
});

it('asks for the shop while no shop has a size', function (): void {
    [$user, $product] = thresholdProduct(sized: false);

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'pack_price' => 22.80])
        ->assertHasErrors(['Give shop_id']);

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 34.55])
        ->assertHasErrors(['Use pack_price with shop_id']);

    expect($product->fresh()?->unit_price_target)->toBeNull();
});

it('refuses the old parameters by naming the new ones', function (string $old, string $new): void {
    [$user, $product] = thresholdProduct();

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, $old => 10])
        ->assertHasErrors([$new]);

    expect($product->fresh()?->unit_price_target)->toBeNull();
})->with([
    'percent' => ['percent', 'percent_under_best'],
    'amount' => ['amount', 'pack_price'],
    'target_price' => ['target_price', 'pack_price'],
]);

it('refuses two target inputs at once, and none', function (array $input): void {
    [$user, $product] = thresholdProduct();
    /** @var array<string, mixed> $arguments */
    $arguments = ['product_id' => (string) $product->id, ...$input];

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, $arguments)
        ->assertHasErrors(['Give exactly one']);
})->with([
    'two' => [['unit_price_target' => 30, 'percent_under_best' => 10]],
    'none' => [[]],
]);

it('refuses a shop of another product', function (): void {
    [$user, $product] = thresholdProduct(sized: false);
    $elsewhere = Shop::factory()->for(Product::factory()->for($user))->create();

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'pack_price' => 22.80, 'shop_id' => (string) $elsewhere->id])
        ->assertHasErrors(['No such shop on this product.']);

    expect($product->fresh()?->unit_price_target)->toBeNull();
});

it('refuses to pin a target to a shop that cannot be bought from', function (): void {
    [$user, $product, $big] = thresholdProduct(sized: false);
    $big->forceFill(['current_in_stock' => false])->save();

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'pack_price' => 22.80, 'shop_id' => (string) $big->id])
        ->assertHasErrors(['That shop is sold out']);

    expect($product->fresh()?->unit_price_target)->toBeNull();
});

it('pins a price for a pack whose size is only borrowed to its shop, never dividing by another shop\'s size', function (): void {
    [$user, $product, $big, $bar] = thresholdProduct(sized: false);
    $bar->forceFill(['pack_quantity' => '55.00', 'pack_unit' => 'g'])->save();

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'pack_price' => 22.80, 'shop_id' => (string) $big->id])
        ->assertOk();

    // Divided by the bar's 55 g it would be €414 a kilo, met at once.
    expect($product->fresh()?->unit_price_target_unit)->toBe('pack')
        ->and($product->fresh()?->unit_price_target_shop_id)->toBe($big->id);
});

it('turns a price for a pack whose size the owner stated into a price per unit', function (): void {
    [$user, $product, $big] = thresholdProduct(sized: false);
    $big->forceFill(['owner_pack_quantity' => '660.00', 'owner_pack_unit' => 'g'])->save();

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'pack_price' => 22.80, 'shop_id' => (string) $big->id])
        ->assertOk();

    expect($product->fresh()?->unit_price_target)->toBe('34.5454')
        ->and($product->fresh()?->unit_price_target_unit)->toBe('g');
});

it('takes a percentage off a shop\'s pack price while nobody wins per unit', function (): void {
    [$user, $product, $big] = thresholdProduct(sized: false);

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'percent_under_best' => 10, 'shop_id' => (string) $big->id])
        ->assertOk();

    expect($product->fresh()?->unit_price_target)->toBe('18.9000')
        ->and($product->fresh()?->unit_price_target_unit)->toBe('pack')
        ->and($product->fresh()?->unit_price_target_shop_id)->toBe($big->id);

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'percent_under_best' => 10])
        ->assertHasErrors(['Give shop_id']);
});
