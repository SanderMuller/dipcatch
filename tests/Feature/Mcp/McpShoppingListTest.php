<?php declare(strict_types=1);

use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\AddToShoppingListTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\RemoveFromShoppingListTool;
use App\Mcp\Tools\ShoppingListTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

/**
 * @param  array{title?: string, listed_at?: DateTimeInterface, list_checked_at?: DateTimeInterface}  $attributes
 */
function mcpListedProduct(User $user, string $host, string $price, array $attributes = []): Product
{
    $product = Product::factory()->create(['user_id' => $user->id, ...$attributes]);
    Shop::factory()->for($product)->create(['url' => "https://{$host}/p/" . $product->id, 'current_price' => $price, 'pack_quantity' => null, 'pack_unit' => null]);
    $product->recomputeCheapestShop();

    return $product->refresh();
}

it('reads the list grouped by the shop to buy each product at', function (): void {
    $me = User::factory()->create();
    $coffee = mcpListedProduct($me, 'ah.nl', '4.99', ['title' => 'Coffee', 'listed_at' => now()]);
    mcpListedProduct($me, 'jumbo.com', '2.00', ['title' => 'Not listed']);

    DipCatchServer::actingAs($me)->tool(ShoppingListTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('open_count', 1)
            ->where('crossed_off_count', 0)
            ->has('groups', 1)
            ->has('groups.0', fn (AssertableJson $group): AssertableJson => $group
                ->where('shop', 'ah.nl')
                ->where('open_count', 1)
                ->has('items', 1)
                ->has('items.0', fn (AssertableJson $item): AssertableJson => $item
                    ->where('product_id', $coffee->id)
                    ->where('title', 'Coffee')
                    ->where('headline_price', '4.99')
                    ->where('headline_price_basis', 'pack')
                    ->where('crossed_off', false)
                    ->etc())));
});

it('adds several products in one call and says which ids it could not use', function (): void {
    $me = User::factory()->create();
    $coffee = mcpListedProduct($me, 'ah.nl', '4.99');
    $tea = mcpListedProduct($me, 'jumbo.com', '2.00');
    $theirs = Product::factory()->create();
    $unknown = (string) Str::uuid();

    DipCatchServer::actingAs($me)
        ->tool(AddToShoppingListTool::class, ['product_ids' => [$coffee->id, Str::upper($tea->id), $theirs->id, $unknown]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('added', fn (Collection $added): bool => $added->sort()->values()->all() === collect([$coffee->id, $tea->id])->sort()->values()->all())
            ->where('already_on_list', [])
            // Another account's product answers exactly as one that never existed.
            ->where('not_found', fn (Collection $missing): bool => $missing->sort()->values()->all() === collect([$theirs->id, $unknown])->sort()->values()->all())
            ->where('list.open_count', 2)
            ->etc());

    expect($coffee->refresh()->isOnShoppingList())->toBeTrue()
        ->and($tea->refresh()->isOnShoppingList())->toBeTrue()
        ->and($theirs->refresh()->isOnShoppingList())->toBeFalse();
});

it('leaves a product already on the list in its place and brings a crossed-off one back', function (): void {
    $me = User::factory()->create();
    $listedAt = now()->subDay()->startOfSecond();
    $open = mcpListedProduct($me, 'ah.nl', '4.99', ['listed_at' => $listedAt]);
    $crossed = mcpListedProduct($me, 'ah.nl', '1.99', ['listed_at' => $listedAt, 'list_checked_at' => now()]);

    DipCatchServer::actingAs($me)
        ->tool(AddToShoppingListTool::class, ['product_ids' => [$open->id, $crossed->id]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('added', [$crossed->id])
            ->where('already_on_list', [$open->id])
            ->etc());

    expect($open->refresh()->listed_at?->equalTo($listedAt))->toBeTrue()
        ->and($crossed->refresh()->isCrossedOff())->toBeFalse();
});

it('takes products off the list and keeps tracking them', function (): void {
    $me = User::factory()->create();
    $listed = mcpListedProduct($me, 'ah.nl', '4.99', ['listed_at' => now()]);
    $unlisted = mcpListedProduct($me, 'ah.nl', '1.99');

    DipCatchServer::actingAs($me)
        ->tool(RemoveFromShoppingListTool::class, ['product_ids' => [$listed->id, $unlisted->id]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('removed', [$listed->id])
            ->where('not_on_list', [$unlisted->id])
            ->where('not_found', [])
            ->where('list.groups', [])
            ->etc());

    expect(Product::query()->whereKey($listed->id)->exists())->toBeTrue()
        ->and($listed->refresh()->isOnShoppingList())->toBeFalse();
});

it('will not take another account off its list', function (): void {
    $theirs = Product::factory()->create(['listed_at' => now()]);

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(RemoveFromShoppingListTool::class, ['product_ids' => [$theirs->id]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('not_found', [$theirs->id])
            ->etc());

    expect($theirs->refresh()->isOnShoppingList())->toBeTrue();
});

it('refuses an empty list or an id that is not a uuid', function (array $productIds): void {
    DipCatchServer::actingAs(User::factory()->create())
        ->tool(AddToShoppingListTool::class, ['product_ids' => $productIds])
        ->assertHasErrors();
})->with([
    'empty' => [[]],
    'not a uuid' => [['not-a-uuid']],
]);

it('tells list_products which products are on the list', function (): void {
    $me = User::factory()->create();
    mcpListedProduct($me, 'ah.nl', '4.99', ['listed_at' => now()]);

    DipCatchServer::actingAs($me)->tool(ListProductsTool::class)
        ->assertOk()
        ->assertSee('"on_shopping_list":true');
});
