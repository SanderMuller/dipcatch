<?php declare(strict_types=1);

use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

use function Pest\Livewire\livewire;

it('lists only the signed-in users products', function (): void {
    $mine = User::factory()->create();
    Product::factory()->create(['user_id' => $mine->id, 'title' => 'My coffee']);
    Product::factory()->create(['title' => 'Somebody elses coffee']);

    $this->actingAs($mine);

    livewire(ProductList::class)
        ->assertSee('My coffee')
        ->assertDontSee('Somebody elses coffee');
});

it('refuses to pause a product owned by someone else', function (): void {
    $mine = User::factory()->create();
    $theirs = Product::factory()->create(['active' => true]);

    $this->actingAs($mine);

    // A forged id in a Livewire call is not covered by the scoped list the page
    // was rendered from — the policy is what refuses it.
    livewire(ProductList::class)
        ->call('togglePaused', $theirs->id)
        ->assertForbidden();

    expect($theirs->fresh()?->active)->toBeTrue();
});

it('pauses and resumes a product it owns', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'active' => true]);

    $this->actingAs($user);

    livewire(ProductList::class)->call('togglePaused', $product->id);
    expect($product->fresh()?->active)->toBeFalse();

    livewire(ProductList::class)->call('togglePaused', $product->id);
    expect($product->fresh()?->active)->toBeTrue();
});

it('searches by title', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Arabica beans']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Dish soap']);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->set('search', 'arabica')
        ->assertSee('Arabica beans')
        ->assertDontSee('Dish soap');
});

it('sorts by a whitelisted column and ignores anything else', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Beta']);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Alpha']);

    $this->actingAs($user);

    $component = livewire(ProductList::class)->call('sortBy', 'title');
    expect($component->get('sort'))->toBe('title');

    // `sort` arrives from the URL, so an unlisted column must not reach the query.
    $component->call('sortBy', 'user_id');
    expect($component->get('sort'))->toBe('title');
});

it('offers the create action below the plan limit', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ProductList::class)->assertSee('Track a product');
});

it('states the limit instead of offering creation when the plan is full', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(20)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductList::class)->assertSee('You have reached your plan limit.');
});

it('renders an empty state and a no-match state', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(ProductList::class)->assertSee('Nothing tracked yet.');

    Product::factory()->create(['user_id' => $user->id, 'title' => 'Coffee']);

    livewire(ProductList::class)
        ->set('search', 'zzzz')
        ->assertSee('No product matches that search.');
});

it('shows the cheapest price and the shop count', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'title' => 'Coffee',
        'currency' => 'EUR',
        'cheapest_price' => '12.49',
    ]);
    Shop::factory()->count(2)->for($product)->create();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('€12.49')
        ->assertSee('2');
});
