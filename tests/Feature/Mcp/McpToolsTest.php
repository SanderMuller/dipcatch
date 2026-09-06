<?php declare(strict_types=1);

use App\Billing\PlanLimits;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Support\DraftToken;
use App\Mcp\Tools\AddShopTool;
use App\Mcp\Tools\CreateProductTool;
use App\Mcp\Tools\DeleteProductTool;
use App\Mcp\Tools\GetProductTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\PriceHistoryTool;
use App\Mcp\Tools\RemoveShopTool;
use App\Mcp\Tools\SetThresholdTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Str;

it('lists only the products of the token owner', function (): void {
    $me = User::factory()->create();
    Product::factory()->create(['user_id' => $me->id, 'title' => 'Mine']);
    Product::factory()->create(['title' => 'Theirs']);

    DipCatchServer::actingAs($me)->tool(ListProductsTool::class)
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

it('answers for another users product exactly as for one that never existed', function (): void {
    $me = User::factory()->create();
    $theirs = Product::factory()->create();

    DipCatchServer::actingAs($me)
        ->tool(GetProductTool::class, ['product_id' => (string) $theirs->id])
        ->assertHasErrors();

    DipCatchServer::actingAs($me)
        ->tool(GetProductTool::class, ['product_id' => (string) Str::uuid()])
        ->assertHasErrors();
});

it('rejects a malformed product id before it reaches the database', function (): void {
    // products.id is a native uuid column: on Postgres a non-uuid raises
    // SQLSTATE 22P02 and on sqlite it returns null, so the schema settles it.
    DipCatchServer::actingAs(User::factory()->create())
        ->tool(GetProductTool::class, ['product_id' => 'not-a-uuid'])
        ->assertHasErrors();
});

it('reads one product with its shops', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id]);
    $shop = Shop::factory()->for($product)->create(['current_price' => '3.00']);
    $product->recomputeCheapestShop();

    DipCatchServer::actingAs($me)->tool(GetProductTool::class, ['product_id' => (string) $product->id])
        ->assertOk()
        ->assertSee((string) $shop->id);
});

it('sets a threshold and refuses an empty one', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id]);

    DipCatchServer::actingAs($me)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'percent' => 12.5])
        ->assertOk();

    expect((float) $product->fresh()?->drop_threshold_pct)->toBe(12.5);

    DipCatchServer::actingAs($me)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id])
        ->assertHasErrors();
});

it('will not set a threshold on another users product', function (): void {
    $theirs = Product::factory()->create(['drop_threshold_pct' => null]);

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(SetThresholdTool::class, ['product_id' => (string) $theirs->id, 'percent' => 50])
        ->assertHasErrors();

    expect($theirs->fresh()?->drop_threshold_pct)->toBeNull();
});

it('removes a shop and recomputes the cheapest', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id]);
    $dear = Shop::factory()->for($product)->create(['current_price' => '9.00']);
    $cheap = Shop::factory()->for($product)->create(['current_price' => '2.00']);
    $product->recomputeCheapestShop();

    expect($product->fresh()?->cheapest_shop_id)->toBe($cheap->id);

    DipCatchServer::actingAs($me)->tool(RemoveShopTool::class, ['shop_id' => (string) $cheap->id])->assertOk();

    expect(Shop::query()->find($cheap->id))->toBeNull()
        ->and($product->fresh()?->cheapest_shop_id)->toBe($dear->id);
});

it('will not remove a shop belonging to another user', function (): void {
    $theirs = Shop::factory()->for(Product::factory()->create())->create();

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(RemoveShopTool::class, ['shop_id' => (string) $theirs->id])
        ->assertHasErrors();

    expect(Shop::query()->find($theirs->id))->not->toBeNull();
});

it('deletes only the owners product', function (): void {
    $me = User::factory()->create();
    $mine = Product::factory()->create(['user_id' => $me->id]);
    $theirs = Product::factory()->create();

    DipCatchServer::actingAs($me)->tool(DeleteProductTool::class, ['product_id' => (string) $theirs->id])->assertHasErrors();
    DipCatchServer::actingAs($me)->tool(DeleteProductTool::class, ['product_id' => (string) $mine->id])->assertOk();

    expect(Product::query()->find($mine->id))->toBeNull()
        ->and(Product::query()->find($theirs->id))->not->toBeNull();
});

it('returns price history for the owners product', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id]);
    Shop::factory()->for($product)->create(['current_price' => '4.00']);
    $product->recomputeCheapestShop();

    DipCatchServer::actingAs($me)->tool(PriceHistoryTool::class, ['product_id' => (string) $product->id])
        ->assertOk()
        ->assertSee('segments');
});

it('refuses to create a product past the plan limit, and writes nothing', function (): void {
    $me = User::factory()->create();
    $limit = (int) app(PlanLimits::class)->remainingProducts($me);
    Product::factory()->count($limit)->create(['user_id' => $me->id]);

    $draft = DraftToken::issue(
        ['title' => 'Coffee', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://ah.nl/p/x',
        'ah',
        variantKey: null,
    );

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true])
        ->assertHasErrors();

    expect($me->products()->count())->toBe($limit);
});

it('creates the product when a valid draft is confirmed', function (): void {
    $me = User::factory()->create();

    $draft = DraftToken::issue(
        ['title' => 'Coffee 500 g', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://ah.nl/p/coffee',
        'ah',
        variantKey: null,
    );

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true])
        ->assertOk()
        ->assertSee('Coffee 500 g');

    expect($me->products()->count())->toBe(1)
        ->and($me->products()->first()?->shops()->count())->toBe(1);
});

it('refuses an unreadable draft rather than writing an old price', function (): void {
    DipCatchServer::actingAs(User::factory()->create())
        ->tool(CreateProductTool::class, ['draft' => 'not-a-real-token', 'confirm' => true])
        ->assertHasErrors();
});

it('will not add a shop to another users product', function (): void {
    $theirs = Product::factory()->create();

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(AddShopTool::class, ['product_id' => (string) $theirs->id, 'url' => 'https://ah.nl/p/x'])
        ->assertHasErrors();

    expect($theirs->shops()->count())->toBe(0);
});
