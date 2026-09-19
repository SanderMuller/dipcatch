<?php declare(strict_types=1);

use App\Billing\PlanLimits;
use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Support\DraftToken;
use App\Mcp\Tools\AddShopTool;
use App\Mcp\Tools\CreateProductTool;
use App\Mcp\Tools\DeleteProductTool;
use App\Mcp\Tools\GetProductTool;
use App\Mcp\Tools\ListCategoriesTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\PriceHistoryTool;
use App\Mcp\Tools\RemoveShopTool;
use App\Mcp\Tools\SetCategoryTool;
use App\Mcp\Tools\SetImageTool;
use App\Mcp\Tools\SetThresholdTool;
use App\Mcp\Tools\SetTitleTool;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Http;
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

it('set_threshold changing the unit price target clears a stale latch', function (): void {
    $me = User::factory()->create();
    subscribeUser($me);
    $product = Product::factory()->create([
        'user_id' => $me->id,
        'unit_price_target' => '5.50',
        'unit_price_notified' => '5.38',
        'unit_price_notified_at' => now(),
    ]);

    DipCatchServer::actingAs($me)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 6.0])
        ->assertOk();

    $fresh = $product->fresh();

    expect((float) $fresh?->unit_price_target)->toBe(6.0)
        ->and($fresh?->unit_price_notified)->toBeNull()
        ->and($fresh?->unit_price_notified_at)->toBeNull();
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

it('renames a product without touching its prices or shops', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Barebells Cookies & Cream - 12 x 55 g kopen']);
    $shop = Shop::factory()->for($product)->create();

    $response = DipCatchServer::actingAs($user)
        ->tool(SetTitleTool::class, [
            'product_id' => (string) $product->id,
            'title' => 'Barebells Protein Bar Cookies & Cream 12 x 55 g',
        ]);

    $response->assertOk();

    $product->refresh();

    expect($product->title)->toBe('Barebells Protein Bar Cookies & Cream 12 x 55 g')
        // The point of the tool: a rename that keeps the history a delete and
        // recreate would have thrown away.
        ->and($product->shops()->pluck('id')->all())->toBe([$shop->id]);
});

it('stores the new name exactly as it was given', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Old name']);
    Shop::factory()->for($product)->create(['url' => 'https://bio-markt.nl/p/1']);

    // Correcting a name the automatic cleanup got wrong is what this tool is
    // for, so it must not run that cleanup over the correction.
    DipCatchServer::actingAs($user)
        ->tool(SetTitleTool::class, [
            'product_id' => (string) $product->id,
            'title' => 'Melk - Bio',
        ])->assertOk();

    expect($product->refresh()->title)->toBe('Melk - Bio');
});

it('refuses a title with nothing in it', function (): void {
    // Caught by the validator's own "required" rule, before the body runs.
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Kept']);

    DipCatchServer::actingAs($user)
        ->tool(SetTitleTool::class, ['product_id' => (string) $product->id, 'title' => '   '])
        ->assertHasErrors();

    expect($product->refresh()->title)->toBe('Kept');
});

it('will not rename another account\'s product', function (): void {
    $theirs = Product::factory()->create(['title' => 'Theirs']);

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(SetTitleTool::class, ['product_id' => (string) $theirs->id, 'title' => 'Mine now'])
        ->assertHasErrors();

    expect($theirs->refresh()->title)->toBe('Theirs');
});

it('lists every department with its categories', function (): void {
    DipCatchServer::actingAs(User::factory()->create())
        ->tool(ListCategoriesTool::class)
        ->assertOk()
        ->assertSee('food.coffee_tea')
        ->assertSee('Coffee & tea')
        ->assertSee('other.other');
});

it('carries the category, its label and its department on a product, null when unset', function (): void {
    $me = User::factory()->create();
    $sorted = Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $me->id, 'title' => 'Sorted']);
    Product::factory()->create(['user_id' => $me->id, 'title' => 'Unsorted']);

    DipCatchServer::actingAs($me)->tool(ListProductsTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('products.0.category', 'food.coffee_tea')
            ->where('products.0.category_label', 'Coffee & tea')
            ->where('products.0.department', 'food')
            ->where('products.1.category', null)
            ->where('products.1.category_label', null)
            ->where('products.1.department', null)
            ->etc());

    DipCatchServer::actingAs($me)->tool(GetProductTool::class, ['product_id' => (string) $sorted->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json->where('category', 'food.coffee_tea')->etc());
});

it('filters the product list by a category or a whole department, and errors on an unknown key', function (): void {
    $me = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $me->id, 'title' => 'Aroma Rood']);
    Product::factory()->categorised(ProductCategory::Frozen)->create(['user_id' => $me->id, 'title' => 'Frozen peas']);
    Product::factory()->categorised(ProductCategory::PetFood)->create(['user_id' => $me->id, 'title' => 'Kitten kibble']);

    DipCatchServer::actingAs($me)->tool(ListProductsTool::class, ['category' => 'food.coffee_tea'])
        ->assertOk()
        ->assertSee('Aroma Rood')
        ->assertDontSee('Frozen peas')
        ->assertDontSee('Kitten kibble');

    DipCatchServer::actingAs($me)->tool(ListProductsTool::class, ['category' => 'food'])
        ->assertOk()
        ->assertSee('Aroma Rood')
        ->assertSee('Frozen peas')
        ->assertDontSee('Kitten kibble');

    DipCatchServer::actingAs($me)->tool(ListProductsTool::class, ['category' => 'unicorns'])
        ->assertHasErrors(['No such category.']);
});

it('stores a category passed on create as the users own choice', function (): void {
    $me = User::factory()->create();
    $draft = DraftToken::issue($me, ['title' => 'Coffee 500 g', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true], 'https://ah.nl/p/coffee', 'ah', variantKey: null);

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true, 'category' => 'food.coffee_tea'])
        ->assertOk()
        ->assertSee('food.coffee_tea');

    $product = $me->products()->sole();

    expect($product->category)->toBe(ProductCategory::CoffeeTea)
        ->and($product->category_set_by)->toBe(CategorySource::User);
});

it('sends nothing to the categoriser when the created product already carries a category', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::fake();
    $me = User::factory()->create(['auto_categories' => true]);
    subscribeUser($me);
    $draft = DraftToken::issue($me, ['title' => 'Coffee 500 g', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true], 'https://ah.nl/p/coffee', 'ah', variantKey: null);

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true, 'category' => 'food.coffee_tea'])
        ->assertOk();

    app()->terminate();

    Http::assertNothingSent();
});

it('rejects a category key the taxonomy does not know on create', function (): void {
    $me = User::factory()->create();
    $draft = DraftToken::issue($me, ['title' => 'Coffee 500 g', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true], 'https://ah.nl/p/coffee', 'ah', variantKey: null);

    DipCatchServer::actingAs($me)
        ->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true, 'category' => 'food.unicorns'])
        ->assertHasErrors();

    expect($me->products()->count())->toBe(0);
});

it('files a product under a category, and records that a person chose it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['category' => null, 'category_set_by' => null]);
    $shop = Shop::factory()->for($product)->create();

    DipCatchServer::actingAs($user)
        ->tool(SetCategoryTool::class, [
            'product_id' => (string) $product->id,
            'category' => 'food.snacks_sweets',
        ])->assertOk();

    $product->refresh();

    expect($product->category)->toBe(ProductCategory::SnacksSweets)
        // Automatic categorisation only writes where this is null, so the
        // choice made here has to survive the next sweep.
        ->and($product->category_set_by)->toBe(CategorySource::User)
        ->and($product->shops()->pluck('id')->all())->toBe([$shop->id]);
});

it('clears a category, and a clear is a choice too', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'category' => ProductCategory::SnacksSweets,
        'category_set_by' => CategorySource::Auto,
    ]);

    DipCatchServer::actingAs($user)
        ->tool(SetCategoryTool::class, ['product_id' => (string) $product->id, 'category' => null])
        ->assertOk();

    $product->refresh();

    expect($product->category)->toBeNull()
        ->and($product->category_set_by)->toBe(CategorySource::User);
});

it('refuses a department key, and says a department is the mistake', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['category' => null]);

    // A live session sent "food" and got "The selected category is invalid",
    // which tells a caller nothing about why, so it retries the same shape.
    DipCatchServer::actingAs($user)
        ->tool(SetCategoryTool::class, ['product_id' => (string) $product->id, 'category' => 'food'])
        ->assertHasErrors()
        ->assertSee('A department key like')
        ->assertSee('food.snacks_sweets');

    expect($product->refresh()->category)->toBeNull();
});

it('will not categorise another account\'s product', function (): void {
    $theirs = Product::factory()->create(['category' => null]);

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(SetCategoryTool::class, ['product_id' => (string) $theirs->id, 'category' => 'food.snacks_sweets'])
        ->assertHasErrors();

    expect($theirs->refresh()->category)->toBeNull();
});

it('shows the picture the named shop reported', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['image_url' => null]);
    $shop = Shop::factory()->for($product)->create(['image_url' => 'https://shop.example.com/bar.jpg']);

    DipCatchServer::actingAs($user)
        ->tool(SetImageTool::class, ['product_id' => (string) $product->id, 'shop_id' => (string) $shop->id])
        ->assertOk();

    expect($product->refresh()->image_url)->toBe('https://shop.example.com/bar.jpg');
});

it('takes no image address from the caller, only a shop', function (): void {
    // The whole point: a picture read off a product page is content the shop
    // controls, so the caller names a shop it already tracks and the server
    // reads that shop's own stored image.
    $properties = data_get(app(SetImageTool::class)->toArray(), 'inputSchema.properties');

    expect($properties)->toBeArray()
        ->and(array_keys((array) $properties))->toBe(['product_id', 'shop_id']);
});

it('refuses a shop that belongs to another product of the same user', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['image_url' => null]);
    $other = Product::factory()->for($user)->create();
    $elsewhere = Shop::factory()->for($other)->create(['image_url' => 'https://shop.example.com/other.jpg']);

    // Same owner, wrong product: that picture is of a different thing.
    DipCatchServer::actingAs($user)
        ->tool(SetImageTool::class, ['product_id' => (string) $product->id, 'shop_id' => (string) $elsewhere->id])
        ->assertHasErrors();

    expect($product->refresh()->image_url)->toBeNull();
});

it('says so when the chosen shop has no picture yet', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['image_url' => 'https://shop.example.com/kept.jpg']);
    $shop = Shop::factory()->for($product)->create(['image_url' => null]);

    DipCatchServer::actingAs($user)
        ->tool(SetImageTool::class, ['product_id' => (string) $product->id, 'shop_id' => (string) $shop->id])
        ->assertHasErrors();

    expect($product->refresh()->image_url)->toBe('https://shop.example.com/kept.jpg');
});

it('will not take a picture for another account\'s product', function (): void {
    $theirs = Product::factory()->create(['image_url' => null]);
    $shop = Shop::factory()->for($theirs)->create(['image_url' => 'https://shop.example.com/theirs.jpg']);

    DipCatchServer::actingAs(User::factory()->create())
        ->tool(SetImageTool::class, ['product_id' => (string) $theirs->id, 'shop_id' => (string) $shop->id])
        ->assertHasErrors();

    expect($theirs->refresh()->image_url)->toBeNull();
});

it('tells a caller which shops can supply a picture', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->for($product)->create(['image_url' => 'https://shop.example.com/has.jpg']);
    Shop::factory()->for($product)->create(['image_url' => null]);

    $response = DipCatchServer::actingAs($user)
        ->tool(GetProductTool::class, ['product_id' => (string) $product->id]);

    $response->assertOk()->assertSee('"has_image":true')->assertSee('"has_image":false');
});

it('carries a title given on the draft call through to the product', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(jsonLdPage('9.99', 'EUR', 'Page Title 500 g'), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();

    // It was read, accepted and dropped: the draft carried the page's title
    // and the confirm had nothing to use, so the parameter looked like it
    // worked and the product got the page's name.
    $draft = DipCatchServer::actingAs($user)
        ->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1', 'title' => 'My own name']);

    $draft->assertOk();

    $token = null;

    $draft->assertStructuredContent(function (AssertableJson $json) use (&$token): void {
        $token = $json->toArray()['draft'] ?? null;
        $json->etc();
    });

    expect($token)->toBeString();

    DipCatchServer::actingAs($user)
        ->tool(CreateProductTool::class, ['draft' => $token, 'confirm' => true])
        ->assertOk();

    expect(Product::query()->where('user_id', $user->id)->value('title'))->toBe('My own name');
});

it('says which shops a per-unit target cannot reach', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->count(2)->for($product)->create(['pack_quantity' => 840, 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1',
        'pack_quantity' => 30,
        'pack_unit' => 'piece',
    ]);

    // Accepted with no warning before this: the alert structurally watched
    // two of three shops and the user saw a threshold that looked set.
    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 7.0])
        ->assertOk()
        ->assertSee('2 of 3 shops report grams')
        ->assertSee('ah.nl')
        ->assertSee('never reaches it');
});

it('says nothing about units when every shop agrees', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->count(2)->for($product)->create(['pack_quantity' => 840, 'pack_unit' => 'g']);

    DipCatchServer::actingAs($user)
        ->tool(SetThresholdTool::class, ['product_id' => (string) $product->id, 'unit_price_target' => 7.0])
        ->assertOk()
        ->assertDontSee('never reaches');
});

it('says at preview time when the pack differs from the shops already tracked', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/2' => Http::response(jsonLdPage('1.99', 'EUR', 'Twix Minis 227 g'), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['pack_quantity' => 333, 'pack_unit' => 'g']);

    // A 227 g bag on a 333 g product reads as a cheaper price rather than a
    // smaller bag, and a caller that does not compare the numbers itself has
    // nothing to show the user.
    DipCatchServer::actingAs($user)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => 'https://shop.example.com/p/2'])
        ->assertOk()
        ->assertSee('This page sells 227 g')
        ->assertSee('already on this product sell 333 g')
        ->assertSee('a different pack, not a cheaper price');
});

it('says nothing when the pack matches', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/3' => Http::response(jsonLdPage('2.49', 'EUR', 'Twix Minis 333 g'), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['pack_quantity' => 333, 'pack_unit' => 'g']);

    DipCatchServer::actingAs($user)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => 'https://shop.example.com/p/3'])
        ->assertOk()
        ->assertDontSee('different pack');
});

it('says nothing when the product already holds a mixture', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/4' => Http::response(jsonLdPage('1.99', 'EUR', 'Twix Minis 227 g'), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['pack_quantity' => 333, 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['pack_quantity' => 240, 'pack_unit' => 'g']);

    // The caller can see the mixture in get_product; repeating it here would
    // fire on every further shop and stop being read.
    DipCatchServer::actingAs($user)
        ->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => 'https://shop.example.com/p/4'])
        ->assertOk()
        ->assertDontSee('different pack');
});
