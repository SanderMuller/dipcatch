<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    configureStripe();
});

it('lists only the products without a category under No category', function (): void {
    $user = User::factory()->create();
    Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id, 'title' => 'Aroma Rood']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Unsorted thing']);
    Product::factory()->create(['title' => 'Somebody elses unsorted thing']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSeeHtml('data-test="product-category-none"')
        ->set('category', ProductList::NO_CATEGORY)
        ->assertSee('Unsorted thing')
        ->assertDontSee('Aroma Rood')
        ->assertDontSee('Somebody elses unsorted thing');
});

it('offers No category only while a product has none, or while it is selected', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->categorised(ProductCategory::CoffeeTea)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)->assertDontSeeHtml('data-test="product-category-none"');

    // Its last product was just sorted: the entry stays, and says the list is done.
    $product->update(['category' => null]);
    $component = livewire(ProductList::class)->set('category', ProductList::NO_CATEGORY);
    $product->update(['category' => ProductCategory::CoffeeTea]);

    $component->call('$refresh')
        ->assertSeeHtml('data-test="product-category-none"')
        ->assertSee('Every product has a category.');
});

it('offers Pro to a free account looking at the products without a category', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertDontSeeHtml('data-test="auto-categories-promo"')
        ->set('category', ProductList::NO_CATEGORY)
        ->assertSeeHtml('data-test="auto-categories-promo"')
        ->assertSee('Let Pro sort your products')
        ->assertSeeHtml('href="' . route('upgrade') . '"');
});

it('says Try Pro while checkout would start a free trial, and Get Pro once it would not', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    config()->set('plans.stripe.trial_days', 14);
    livewire(ProductList::class)
        ->set('category', ProductList::NO_CATEGORY)
        ->assertSee('Try Pro')
        ->assertDontSee('Get Pro');

    config()->set('plans.stripe.trial_days', 0);
    livewire(ProductList::class)
        ->set('category', ProductList::NO_CATEGORY)
        ->assertSee('Get Pro')
        ->assertDontSee('Try Pro');
});

it('does not sell Pro to a Pro account', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', ProductList::NO_CATEGORY)
        ->assertDontSeeHtml('data-test="auto-categories-promo"');
});

it('does not sell Pro while checkout is closed', function (): void {
    config()->set('plans.enabled', false);
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', ProductList::NO_CATEGORY)
        ->assertDontSeeHtml('data-test="auto-categories-promo"');
});

it('does not sell Pro to an account blocked from billing', function (): void {
    $user = User::factory()->create(['billing_blocked_at' => now()]);
    Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', ProductList::NO_CATEGORY)
        ->assertDontSeeHtml('data-test="auto-categories-promo"');
});

it('does not sell a feature this install has switched off', function (): void {
    config()->set('services.typesafe.key', '');
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('category', ProductList::NO_CATEGORY)
        ->assertDontSeeHtml('data-test="auto-categories-promo"');
});
