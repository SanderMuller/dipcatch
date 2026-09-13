<?php declare(strict_types=1);

use App\Livewire\Products\EditProduct;
use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

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
        ->set('unitPriceTarget', '11.00')
        ->set('active', false)
        ->call('save')
        ->assertRedirect(route('app.products.show', $product));

    $fresh = $product->fresh();

    expect($fresh?->title)->toBe('Beemster 48+ 150 g')
        ->and($fresh?->image_url)->toBe('https://example.test/pack.jpg')
        ->and((string) $fresh?->drop_threshold_pct)->toBe('15.00')
        ->and((string) $fresh?->target_price)->toBe('2.49')
        ->and((string) $fresh?->unit_price_target)->toBe('11.00')
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

it('keeps a unit price target a free account cannot be alerted on', function (): void {
    // The number is stored on any plan and starts working on upgrade, which
    // is what DetectUnitPriceTarget already assumes.
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    expect($user->entitlements()->allowsUnitPriceAlerts())->toBeFalse();

    livewire(EditProduct::class, ['product' => $product])
        ->set('unitPriceTarget', '3.20')
        ->call('save');

    expect((string) $product->fresh()?->unit_price_target)->toBe('3.20');
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

    $this->get(route('app.products.show', $product))
        ->assertOk()
        ->assertSee(route('app.products.edit', $product), escape: false);
});

it('opens a shop for editing from the product page and saves a note', function (): void {
    // Both save methods survived the cutover with tests on them; nothing in
    // the view called them, so neither could be reached.
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    $shop = Shop::factory()->create(['product_id' => $product->id, 'notes' => null]);

    $this->actingAs($user);

    $this->get(route('app.products.show', $product))
        ->assertOk()
        ->assertSee("editShop('" . $shop->id . "')", escape: false);

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
