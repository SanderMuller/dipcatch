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
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

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

    // "Exactly" is the whole point: a different message for a real id that
    // belongs to someone else turns the endpoint into an existence oracle.
    // The same literal on both branches: a distinct message for a real id
    // belonging to someone else would make the endpoint an existence oracle.
    DipCatchServer::actingAs($me)
        ->tool(GetProductTool::class, ['product_id' => (string) $theirs->id])
        ->assertHasErrors(['No such product.']);

    DipCatchServer::actingAs($me)
        ->tool(GetProductTool::class, ['product_id' => (string) Str::uuid()])
        ->assertHasErrors(['No such product.']);
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

it('returns bundle conditions beside tracked MCP prices', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id]);
    $shop = Shop::factory()->for($product)->create([
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
    ]);
    $product->recomputeCheapestShop();

    DipCatchServer::actingAs($me)->tool(GetProductTool::class, ['product_id' => (string) $product->id])
        ->assertOk()
        ->assertSee('"cheapest_price":"2.00"')
        ->assertSee('"cheapest_single_item_price":"2.85"')
        ->assertSee('"cheapest_bundle_quantity":2')
        ->assertSee('"cheapest_bundle_total_price":"4.00"');
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
        $me,
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
        $me,
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

it('refuses a draft that expired between showing it and confirming it', function (): void {
    // The whole point of the token is that confirm writes what the user was
    // shown. A stale one must say so rather than silently re-fetching and
    // storing a price nobody approved.
    $me = User::factory()->create();

    $draft = DraftToken::issue(
        $me,
        ['title' => 'Coffee', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://ah.nl/p/coffee',
        'ah',
        variantKey: null,
    );

    $this->travel(16)->minutes();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true])
        ->assertHasErrors()
        ->assertSee('expired');

    expect($me->products()->count())->toBe(0);
});

it('still accepts a draft inside its window', function (): void {
    $me = User::factory()->create();

    $draft = DraftToken::issue(
        $me,
        ['title' => 'Coffee', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://ah.nl/p/coffee',
        'ah',
        variantKey: null,
    );

    $this->travel(14)->minutes();

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true])
        ->assertOk();

    expect($me->products()->count())->toBe(1);
});

it('will not let one account spend a draft issued to another', function (): void {
    // The token records that a particular user approved what they were shown.
    // Without the binding it is a bearer credential for whoever holds it.
    $issuer = User::factory()->create();

    $draft = DraftToken::issue(
        $issuer,
        ['title' => 'Coffee', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://ah.nl/p/coffee',
        'ah',
        variantKey: null,
    );

    $other = User::factory()->create();

    DipCatchServer::actingAs($other)
        ->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true])
        ->assertHasErrors();

    expect($other->products()->count())->toBe(0)
        ->and($issuer->products()->count())->toBe(0);
});

it('sets the unit price target, and says when the account is not alerted on it', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'unit_price_target' => null]);

    DipCatchServer::actingAs($me)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 6.5])
        ->assertOk()
        ->assertSee('Pro feature');

    expect((float) $product->fresh()?->unit_price_target)->toBe(6.5);
});

it('sets the unit price target without a caveat for a Pro account', function (): void {
    $me = User::factory()->create();
    subscribeUser($me);
    $product = Product::factory()->create(['user_id' => $me->id, 'unit_price_target' => null]);

    DipCatchServer::actingAs($me)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 6.5])
        ->assertOk()
        ->assertDontSee('Pro feature');

    expect((float) $product->fresh()?->unit_price_target)->toBe(6.5);
});

it('leaves the drop thresholds alone when only a unit price target is given', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $me->id,
        'drop_threshold_pct' => '10.00',
        'drop_threshold_abs' => null,
    ]);

    DipCatchServer::actingAs($me)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 4.25])
        ->assertOk();

    $fresh = $product->fresh();

    expect((float) $fresh?->drop_threshold_pct)->toBe(10.0)
        ->and($fresh?->drop_threshold_abs)->toBeNull()
        ->and((float) $fresh?->unit_price_target)->toBe(4.25);
});

it('says whether the cheapest shop can actually be bought', function (): void {
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id, 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['current_price' => '9.00', 'currency' => 'EUR', 'current_in_stock' => true]);
    Shop::factory()->for($product)->create(['current_price' => '4.00', 'currency' => 'EUR', 'current_in_stock' => null]);

    $product->recomputeCheapestShop();

    DipCatchServer::actingAs($me)->tool(ListProductsTool::class)
        ->assertOk()
        ->assertSee('"cheapest_stock":"unknown"');
});

it('returns a price segment that started before the plan window and is still open', function (): void {
    // A free account reads 90 days. A price that has not moved for over a
    // year is one open segment starting outside that window, so the shared
    // scope must keep it. This tool already matched on overlap before the
    // scope existed; the case guards the refactor, not a fix.
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(400),
        'ended_at' => null,
    ]);

    DipCatchServer::actingAs($me)
        ->tool(PriceHistoryTool::class, ['product_id' => (string) $product->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('segments', 1)
            ->where('segments.0.price', '85.00')
            ->etc());
});

it('omits a segment that both started and ended before the plan window', function (): void {
    // The overlap fix must not widen the window into "return everything".
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '999.99',
        'started_at' => now()->subDays(400),
        'ended_at' => now()->subDays(300),
    ]);

    DipCatchServer::actingAs($me)
        ->tool(PriceHistoryTool::class, ['product_id' => (string) $product->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('segments', 0)
            ->etc());
});

it('returns a segment that started before the plan window and ended inside it', function (): void {
    // Only `ended_at >= $windowStart` can match this shape. Without that
    // clause the chart starts at the change instead of showing the level the
    // price dropped from.
    $me = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $me->id]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '120.00',
        'started_at' => now()->subDays(300),
        'ended_at' => now()->subDays(20),
    ]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '85.00',
        'started_at' => now()->subDays(20),
        'ended_at' => null,
    ]);

    DipCatchServer::actingAs($me)
        ->tool(PriceHistoryTool::class, ['product_id' => (string) $product->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('segments', 2)
            ->where('segments.0.price', '120.00')
            ->where('segments.1.price', '85.00')
            ->etc());
});

it('returns history older than the free window to a pro account', function (): void {
    // Pro reads an unlimited window, which reaches the scope as a null
    // cutoff. The scope no-ops there, so the guard that drops a closed
    // segment on free must not drop it here.
    $me = User::factory()->create();
    subscribeUser($me, 'active');
    $product = Product::factory()->create(['user_id' => $me->id]);
    ProductCheapestHistory::factory()->for($product)->create([
        'cheapest_shop_id' => null,
        'cheapest_price' => '999.99',
        'started_at' => now()->subDays(400),
        'ended_at' => now()->subDays(300),
    ]);

    DipCatchServer::actingAs($me)
        ->tool(PriceHistoryTool::class, ['product_id' => (string) $product->id])
        ->assertOk()
        ->assertSee('999.99');
});
