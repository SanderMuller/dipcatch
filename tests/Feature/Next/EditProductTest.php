<?php declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Enums\ShopHealth;
use App\Livewire\Products\EditProduct;
use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Livewire\livewire;

it('loads the product into the form', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'title' => 'Coffee beans 1 kg',
        'drop_threshold_pct' => '12.50',
        'target_price' => '15.00',
    ]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSet('title', 'Coffee beans 1 kg')
        ->assertSet('dropThresholdPct', '12.50')
        ->assertSet('targetPrice', '15.00');
});

it('saves every field the old Filament form carried', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'active' => true]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('title', '  Beemster 48+ 150 g  ')
        ->set('imageUrl', 'https://example.test/pack.jpg')
        ->set('currency', 'EUR')
        ->set('dropThresholdPct', '15')
        ->set('dropThresholdAbs', '0.75')
        ->set('targetPrice', '2.49')
        ->set('unitPriceTarget', '11.0000')
        ->set('active', false)
        ->call('save')
        ->assertRedirect(route('app.products.show', $product));

    $fresh = $product->fresh();

    expect($fresh?->title)->toBe('Beemster 48+ 150 g')
        ->and($fresh?->image_url)->toBe('https://example.test/pack.jpg')
        ->and((string) $fresh?->drop_threshold_pct)->toBe('15.00')
        ->and((string) $fresh?->target_price)->toBe('2.49')
        ->and((string) $fresh?->unit_price_target)->toBe('11.0000')
        ->and($fresh?->active)->toBeFalse();
});

it('reads an empty field as no alert of that kind', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'target_price' => '9.99',
        'unit_price_target' => '4.00',
    ]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('targetPrice', '')
        ->set('unitPriceTarget', '   ')
        ->call('save');

    $fresh = $product->fresh();

    expect($fresh?->target_price)->toBeNull()
        ->and($fresh?->unit_price_target)->toBeNull();
});

it('clears a stale target latch when the target changes through the edit form', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'target_price' => '18.00',
        'target_price_notified' => '17.05',
        'target_price_notified_at' => now(),
        'unit_price_target' => '5.50',
        'unit_price_notified' => '5.38',
        'unit_price_notified_at' => now(),
    ]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('targetPrice', '30.00')
        ->set('unitPriceTarget', '6.00')
        ->call('save');

    $fresh = $product->fresh();

    expect($fresh?->target_price_notified)->toBeNull()
        ->and($fresh?->target_price_notified_at)->toBeNull()
        ->and($fresh?->unit_price_notified)->toBeNull()
        ->and($fresh?->unit_price_notified_at)->toBeNull();
});

it('leaves an armed latch alone when the edit form does not change the target', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'target_price' => '18.00',
        'target_price_notified' => '17.05',
        'target_price_notified_at' => now(),
    ]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('title', 'A different title')
        ->call('save');

    $fresh = $product->fresh();

    expect($fresh?->target_price_notified)->toBe('17.05')
        ->and($fresh?->target_price_notified_at)->not->toBeNull();
});

it('refuses a threshold of zero rather than alerting on a price that did not move', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'drop_threshold_pct' => '10.00']);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('dropThresholdPct', '0')
        ->call('save')
        ->assertHasErrors('dropThresholdPct');

    expect((string) $product->fresh()?->drop_threshold_pct)->toBe('10.00');
});

it('refuses a title that is not there', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Kept']);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('title', '')
        ->call('save')
        ->assertHasErrors('title');

    expect($product->fresh()?->title)->toBe('Kept');
});

it('refuses an image url whose scheme is not http(s)', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'image_url' => null]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('imageUrl', 'ftp://example.test/pack.jpg')
        ->call('save')
        ->assertHasErrors('imageUrl');

    expect($product->fresh()?->image_url)->toBeNull();
});

it('keeps a unit price target a free account cannot be alerted on', function (): void {
    // The number is stored on any plan and starts working on upgrade, which
    // is what DetectUnitPriceTarget already assumes.
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    expect($user->entitlements()->allowsUnitPriceAlerts())->toBeFalse();

    livewire(EditProduct::class, ['product' => $product])
        ->set('unitPriceTarget', '3.2000')
        ->call('save');

    expect((string) $product->fresh()?->unit_price_target)->toBe('3.2000');
});

it('offers the images the shops reported', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    Shop::factory()->create([
        'product_id' => $product->id,
        'host' => 'ah.nl',
        'image_url' => 'https://static.ah.test/pack.jpg',
    ]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->fresh()])
        ->assertSee('https://static.ah.test/pack.jpg', escape: false)
        ->call('useShopImage', 0)
        ->assertSet('imageUrl', 'https://static.ah.test/pack.jpg');
});

it('deletes the product on request', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->call('delete')
        ->assertRedirect(route('app.products.index'));

    expect(Product::query()->whereKey($product->id)->exists())->toBeFalse();
});

it('refuses to open somebody elses product', function (): void {
    $this->actingAs(User::factory()->create());

    $stranger = Product::factory()->create();

    livewire(EditProduct::class, ['product' => $stranger])->assertForbidden();
});

it('reaches the form from the product page', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $this->get(route('app.products.show', $product))->assertOk()->assertSeeHtml(route('app.products.edit', $product));
});

it('opens a shop for editing from the product page and saves a note', function (): void {
    // Both save methods survived the cutover with tests on them; nothing in
    // the view called them, so neither could be reached.
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    $shop = Shop::factory()->create(['product_id' => $product->id, 'notes' => null]);

    $this->actingAs($user);

    $this->get(route('app.products.show', $product))->assertOk()->assertSeeHtml("editShop('" . $shop->id . "')");

    livewire(ProductShow::class, ['product' => $product])
        ->call('editShop', $shop->id)
        ->assertSet('editingUrl', $shop->url)
        ->set('editingNotes', "ships only to NL\ncoupon CODE10")
        ->call('saveEditedNotes')
        ->assertSet('shopMessage', 'Notes saved');

    expect($shop->fresh()?->notes)->toBe("ships only to NL\ncoupon CODE10");
});

it('refuses to open a shop on somebody elses product', function (): void {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create();
    $stranger = Shop::factory()->create(['product_id' => $product->id]);
    $mine = Product::factory()->create(['user_id' => auth()->id()]);

    livewire(ProductShow::class, ['product' => $mine])
        ->call('editShop', $stranger->id)
        ->assertForbidden();

    livewire(ProductShow::class, ['product' => $mine])
        ->call('saveShopUrl', $stranger->id, 'https://shop.example.com/p/9')
        ->assertForbidden();

    expect($stranger->fresh()?->url)->toBe($stranger->url);
});

it('refuses a shop of my own that belongs to another product', function (): void {
    // The policy would allow it — it is this account's shop. The page must
    // still refuse: it would edit or delete something nobody can see on it.
    $user = User::factory()->create();
    $shown = Product::factory()->create(['user_id' => $user->id]);
    $elsewhere = Product::factory()->create(['user_id' => $user->id]);
    $shop = Shop::factory()->create([
        'product_id' => $elsewhere->id,
        'notes' => 'untouched',
        'current_price' => '10.00',
    ]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $shown])
        ->call('editShop', $shop->id)
        ->assertNotFound();

    livewire(ProductShow::class, ['product' => $shown])
        ->call('saveShopUrl', $shop->id, 'https://shop.example.com/p/9')
        ->assertNotFound();

    livewire(ProductShow::class, ['product' => $shown])
        ->call('saveShopNotes', $shop->id, 'changed')
        ->assertNotFound();

    livewire(ProductShow::class, ['product' => $shown])
        ->call('removeShop', $shop->id)
        ->assertNotFound();

    $untouched = $shop->fresh();

    expect($untouched?->notes)->toBe('untouched')
        ->and((string) $untouched?->current_price)->toBe('10.00');
});

it('saves a category chosen by hand and marks it as the users choice', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSee('No category')
        ->assertSee('Coffee & tea')
        ->set('category', 'food.coffee_tea')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $product->fresh();

    expect($fresh?->category)->toBe(ProductCategory::CoffeeTea)
        ->and($fresh?->category_set_by)->toBe(CategorySource::User);
});

it('marks a cleared category as the users choice so nothing fills it again', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()
        ->categorised(ProductCategory::CoffeeTea, CategorySource::Auto)
        ->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSet('category', 'food.coffee_tea')
        ->set('category', '')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $product->fresh();

    expect($fresh?->category)->toBeNull()
        ->and($fresh?->category_set_by)->toBe(CategorySource::User);
});

it('keeps an automatic category as automatic when the form is saved without touching it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()
        ->categorised(ProductCategory::CoffeeTea, CategorySource::Auto)
        ->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('title', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()?->category_set_by)->toBe(CategorySource::Auto);
});

it('rejects a category the taxonomy does not know', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->set('category', 'food.unicorns')
        ->call('save')
        ->assertHasErrors(['category']);

    expect($product->fresh()?->category)->toBeNull();
});

it('keeps a category the automatic job wrote while the form was open', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $form = livewire(EditProduct::class, ['product' => $product])->assertSet('category', '');

    $product->forceFill(['category' => ProductCategory::CoffeeTea, 'category_set_by' => CategorySource::Auto])->save();

    $form->set('title', 'Renamed while sorting')->call('save')->assertHasNoErrors();

    $fresh = $product->fresh();

    expect($fresh?->category)->toBe(ProductCategory::CoffeeTea)
        ->and($fresh?->category_set_by)->toBe(CategorySource::Auto);
});

it('names the unit once the shops have read a pack size', function (string $unit, string $expected): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->for($product)->create(['pack_quantity' => 500, 'pack_unit' => $unit]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSee($expected)
        ->assertDontSee('Target price per kilo, litre or piece');
})->with([
    'grams' => ['g', 'same price per kilo'],
    'millilitres' => ['ml', 'same price per litre'],
    'pieces' => ['piece', 'same price per piece'],
]);

it('names the unit the comparison actually uses when the shops disagree', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->count(2)->for($product)->create(['pack_quantity' => 500, 'pack_unit' => 'g']);
    // One shop reporting pieces against two reporting grams is ordinary, and
    // best value compares inside the larger group, so the label follows it
    // rather than offering a choice the reader does not have.
    Shop::factory()->for($product)->create(['pack_quantity' => 12, 'pack_unit' => 'piece']);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSee('same price per kilo')
        ->assertDontSee('same price per piece');
});

it('keeps the three-way wording while no shop has read a pack size', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->for($product)->create(['pack_quantity' => null, 'pack_unit' => null]);

    $this->actingAs($user);

    // The explanation under the field is the Pro one for a free account, so
    // this pins the label; the resolver is what decides both.
    livewire(EditProduct::class, ['product' => $product])
        ->assertSee('Target price per kilo, litre or piece');

    expect($product->comparablePacks()->unit())->toBeNull();
});

it('shows a free account the suggestion button disabled, with the way to Pro', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::fake();
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSee('Suggest a category')
        ->assertSee('Pro suggests a category for you.')
        ->assertSeeHtml(route('upgrade'))
        ->call('suggestCategory')
        ->assertSet('suggestedCategory', null);

    Http::assertNothingSent();
});

it('hides the suggestion button without a key, and when a category is already set', function (): void {
    config()->set('services.typesafe.key', '');
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])->assertDontSee('Suggest a category');

    config()->set('services.typesafe.key', 'test-key');
    $sorted = Product::factory()->categorised(ProductCategory::PetFood)->create(['user_id' => $user->id]);

    livewire(EditProduct::class, ['product' => $sorted])->assertDontSee('Suggest a category');
});

it('suggests a category to a Pro account, which can use it and save it as its own choice', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 0.95, 'home' => 0.05], ['food' => ['coffee_tea' => 0.95, 'pantry' => 0.05]]))]);
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Aroma Rood 500 g']);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->assertSee('Suggest a category')
        ->call('suggestCategory')
        ->assertSet('suggestedCategory', 'food.coffee_tea')
        ->assertSee('Suggested:')
        ->assertSee('Coffee & tea')
        ->assertSee('Use it')
        ->call('acceptSuggestion')
        ->assertSet('category', 'food.coffee_tea')
        ->assertSet('suggestedCategory', null)
        ->assertSee('Category set. Save changes to keep it.')
        ->call('save')
        ->assertHasNoErrors();

    Http::assertSentCount(1);
    expect($product->fresh()?->category)->toBe(ProductCategory::CoffeeTea)
        ->and($product->fresh()?->category_set_by)->toBe(CategorySource::User);
});

it('drops a declined suggestion and leaves the category empty', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 0.95, 'home' => 0.05], ['food' => ['coffee_tea' => 0.95, 'pantry' => 0.05]]))]);
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->call('suggestCategory')
        ->assertSet('suggestedCategory', 'food.coffee_tea')
        ->call('declineSuggestion')
        ->assertSet('suggestedCategory', null)
        ->assertSet('category', '')
        ->assertSee('Suggest a category');
});

it('says so when the suggestion request fails, and stores nothing', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::fake([TypeSafeClient::ENDPOINT => Http::response([], 401)]);
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->call('suggestCategory')
        ->assertSet('suggestedCategory', null)
        ->assertSee('No suggestion right now. Try again in a moment.');

    expect($product->fresh()?->category)->toBeNull();
});

it('keeps a suggestion, so the next visit shows it without asking again', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 0.95, 'home' => 0.05], ['food' => ['coffee_tea' => 0.95, 'pantry' => 0.05]]))]);
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])->call('suggestCategory');

    expect($product->fresh()?->suggested_category)->toBe(ProductCategory::CoffeeTea);

    livewire(EditProduct::class, ['product' => $product->fresh()])
        ->assertSet('suggestedCategory', 'food.coffee_tea')
        ->assertSee('Use it')
        ->call('suggestCategory')
        ->assertSet('suggestedCategory', 'food.coffee_tea');

    Http::assertSentCount(1);
});

it('forgets a declined suggestion as the users decision, and a saved category clears it', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    $user = User::factory()->create();
    subscribeUser($user);
    $declined = Product::factory()->create(['user_id' => $user->id, 'suggested_category' => ProductCategory::CoffeeTea]);
    $accepted = Product::factory()->create(['user_id' => $user->id, 'suggested_category' => ProductCategory::CoffeeTea]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $declined])
        ->assertSet('suggestedCategory', 'food.coffee_tea')
        ->call('declineSuggestion');

    livewire(EditProduct::class, ['product' => $accepted])
        ->call('acceptSuggestion')
        ->call('save')
        ->assertHasNoErrors();

    expect($declined->fresh()?->suggested_category)->toBeNull()
        ->and($declined->fresh()?->category_set_by)->toBe(CategorySource::User)
        ->and($accepted->fresh()?->suggested_category)->toBeNull()
        ->and($accepted->fresh()?->category)->toBe(ProductCategory::CoffeeTea);
});

it('tells a Pro account when the daily suggestion budget is spent, and sends nothing', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.categories.daily_limit_per_user', 1);
    Http::fake();
    $user = User::factory()->create();
    subscribeUser($user);
    RateLimiter::hit(CategorisationBudget::userKey($user), 86400);
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->call('suggestCategory')
        ->assertSet('suggestedCategory', null)
        ->assertSee("You have used today's suggestions.");

    Http::assertNothingSent();
});

it('shows what the product costs now beside each target field', function (): void {
    // Setting a target means picking a number relative to today's price. Asking
    // the reader to remember it, or to open the product page in another tab,
    // is the difference between a considered figure and a guess.
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    // The Lay's case, where the two answers are different shops: ah is the
    // smaller outlay, dirk the better value.
    foreach ([
        ['host' => 'ah.nl', 'price' => '2.19', 'quantity' => '200.00'],
        ['host' => 'dirk.nl', 'price' => '2.45', 'quantity' => '300.00'],
    ] as $row) {
        Shop::factory()->for($product)->create(['url' => 'https://' . $row['host'] . '/p/1'])
            ->forceFill([
                'currency' => 'EUR',
                'current_price' => $row['price'],
                'current_in_stock' => true,
                'pack_quantity' => $row['quantity'],
                'pack_unit' => 'g',
            ])->save();
    }

    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        // The target price is an outlay figure, so it is anchored to the
        // smallest outlay.
        ->assertSee('Now €2.19 at ah.nl')
        // The per-unit target is anchored to the best value, which here is the
        // other shop.
        ->assertSee('Now €8.17 /kg at dirk.nl');
});

it('leaves the now-line out when no shop has a usable price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    $this->actingAs($user);

    // The whole sentence, not a fragment: "Now " on its own would pass for the
    // wrong reason the moment any other copy on this page starts with it, and
    // it proves nothing about which description rendered.
    livewire(EditProduct::class, ['product' => $product])
        ->assertSee('We tell you when any shop reaches this price.')
        ->assertDontSee('We tell you when any shop reaches this price. Now');
});

it('anchors to a shop a shopper can actually buy from', function (): void {
    // A dead shop still states a size, so it can still resolve a unit price —
    // but it cannot win the ranking, and naming it here would put a figure on
    // this form that no other surface and no alert agrees with.
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    Shop::factory()->for($product)->create(['url' => 'https://live.nl/p/1'])
        ->forceFill([
            'currency' => 'EUR', 'current_price' => '10.00', 'current_in_stock' => true,
            'pack_quantity' => '500.00', 'pack_unit' => 'g',
        ])->save();

    Shop::factory()->for($product)->create(['url' => 'https://dead.nl/p/1'])
        ->forceFill([
            'currency' => 'EUR', 'current_price' => '1.00', 'current_in_stock' => true,
            'pack_quantity' => '500.00', 'pack_unit' => 'g', 'health' => ShopHealth::Dead,
        ])->save();

    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSee('Now €10.00 at live.nl')
        ->assertSee('Now €20.00 /kg at live.nl')
        ->assertDontSee('dead.nl');

    // And the form agrees with the answer the ranking stored.
    expect($product->refresh()->bestValueShop()?->host)->toBe('live.nl');
});

it('anchors to the shop that is cheapest now, not the one a recompute last named', function (): void {
    // `cheapest_shop_id` only moves on a recompute. Reading it here would name
    // ah.nl at €5.00 while dirk.nl already sells it for €4.00 — a target set
    // against a price the reader cannot get.
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    $first = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1']);
    $first->forceFill(['currency' => 'EUR', 'current_price' => '5.00', 'current_in_stock' => true])->save();

    $product->refresh()->recomputeCheapestShop();

    $second = Shop::factory()->for($product)->create(['url' => 'https://dirk.nl/p/1']);
    $second->forceFill(['currency' => 'EUR', 'current_price' => '4.00', 'current_in_stock' => true])->save();

    $this->actingAs($user);

    // Deliberately not recomputed.
    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSee('Now €4.00 at dirk.nl');
});

it('shows a free account the current figure beside the upgrade line', function (): void {
    // The number is worth setting before an upgrade, and it is the one thing
    // that makes it possible to pick.
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1'])
        ->forceFill([
            'currency' => 'EUR', 'current_price' => '2.00', 'current_in_stock' => true,
            'pack_quantity' => '500.00', 'pack_unit' => 'g',
        ])->save();

    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSee('Pro alerts on this.')
        ->assertSee('Now €4.00 /kg at ah.nl')
        // The note says where to upgrade, not only that one is needed.
        ->assertSeeInOrder(['Pro alerts on this.', 'Get Pro']);
});

it('shows the unit target without the zeros its column pads it with', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    $product->forceFill(['unit_price_target' => '7.0000'])->save();

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])->assertSet('unitPriceTarget', '7');
});

it('opens the other alerts when one of them is set, and folds them away when none is', function (): void {
    $user = User::factory()->create();
    $set = Product::factory()->create(['user_id' => $user->id, 'drop_threshold_pct' => '25.00', 'drop_threshold_abs' => null, 'target_price' => null]);
    $empty = Product::factory()->create(['user_id' => $user->id, 'drop_threshold_pct' => null, 'drop_threshold_abs' => null, 'target_price' => null]);

    $this->actingAs($user);

    $open = fn (Product $product): bool => (bool) preg_match('/<details[^>]*\bopen\b[^>]*data-test="other-alerts"/s', livewire(EditProduct::class, ['product' => $product])->html());

    expect($open($set))->toBeTrue()
        ->and($open($empty))->toBeFalse();
});

it('switches a drop alert to a price alert at the same saving', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'drop_threshold_pct' => '25.00', 'drop_threshold_abs' => '1.00', 'unit_price_target' => null]);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1'])
        ->forceFill(['currency' => 'EUR', 'current_price' => '6.15', 'current_in_stock' => true, 'pack_quantity' => '840.00', 'pack_unit' => 'g'])->save();
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    // €6.15 for 840 g is €7.3214 a kilo. 25% off is €5.4910; €1.00 off the
    // pack is €6.1309. The drop check alerts on whichever is met first, so
    // the easier one, €6.1309, carries over, and both are named.
    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSeeHtml('data-test="price-alert-switch"')
        ->assertSee('Instead of your 25% or €1.00 drop alert')
        ->call('switchToPriceAlert')
        ->assertSet('unitPriceTarget', '6.1309')
        ->assertSet('dropThresholdPct', null)
        ->assertSet('dropThresholdAbs', null)
        ->assertDontSeeHtml('data-test="price-alert-switch"')
        ->call('save')
        ->assertHasNoErrors();

    $saved = $product->fresh();
    expect((string) $saved?->unit_price_target)->toBe('6.1309')
        ->and($saved?->drop_threshold_pct)->toBeNull()
        ->and($saved?->drop_threshold_abs)->toBeNull();
});

it('offers no switch once the product has a price alert', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'drop_threshold_pct' => '25.00', 'unit_price_target' => '5.00']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1'])
        ->forceFill(['currency' => 'EUR', 'current_price' => '6.15', 'current_in_stock' => true, 'pack_quantity' => '840.00', 'pack_unit' => 'g'])->save();
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])->assertDontSeeHtml('data-test="price-alert-switch"');
});

it('keeps a free account on its drop alert', function (): void {
    // A free account's price alert is stored but not checked, so switching
    // would leave it with no alert of its own.
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'drop_threshold_pct' => '25.00', 'unit_price_target' => null]);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1'])
        ->forceFill(['currency' => 'EUR', 'current_price' => '6.15', 'current_in_stock' => true, 'pack_quantity' => '840.00', 'pack_unit' => 'g'])->save();
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertDontSeeHtml('data-test="price-alert-switch"')
        ->call('switchToPriceAlert')
        ->assertSet('dropThresholdPct', '25.00')
        ->assertSet('unitPriceTarget', null);
});

it('offers no switch when the same saving leaves less than the smallest target', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    // €1 for 100 pieces is a cent a piece; 99.5% off that is below 0.0001.
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'drop_threshold_pct' => '99.50', 'unit_price_target' => null]);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1'])
        ->forceFill(['currency' => 'EUR', 'current_price' => '1.00', 'current_in_stock' => true, 'pack_quantity' => '100.00', 'pack_unit' => 'piece'])->save();
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertDontSeeHtml('data-test="price-alert-switch"')
        ->call('switchToPriceAlert')
        ->assertSet('dropThresholdPct', '99.50');
});

it('restates a percentage drop without a float cutting 4.2 to 4.1999', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'drop_threshold_pct' => '30.00', 'drop_threshold_abs' => null, 'unit_price_target' => null]);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1'])
        ->forceFill(['currency' => 'EUR', 'current_price' => '6.00', 'current_in_stock' => true, 'pack_quantity' => '1000.00', 'pack_unit' => 'g'])->save();
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSee('€4.20 per kilo')
        ->call('switchToPriceAlert')
        ->assertSet('unitPriceTarget', '4.2');
});

it('restates a money drop off the best pack', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'drop_threshold_pct' => null, 'drop_threshold_abs' => '1.00', 'unit_price_target' => null]);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1'])
        ->forceFill(['currency' => 'EUR', 'current_price' => '5.00', 'current_in_stock' => true, 'pack_quantity' => '500.00', 'pack_unit' => 'g'])->save();
    $product->refresh()->recomputeCheapestShop();

    $this->actingAs($user);

    // €5.00 for 500 g, €1.00 off: €4.00 for the pack, €8 a kilo.
    livewire(EditProduct::class, ['product' => $product->refresh()])
        ->assertSee('Instead of your €1.00 drop alert')
        ->call('switchToPriceAlert')
        ->assertSet('unitPriceTarget', '8');
});
