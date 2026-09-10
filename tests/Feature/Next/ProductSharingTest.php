<?php declare(strict_types=1);

use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    // The public page is throttled through Redis, whose counters outlive the
    // test run: without this, asking for the page a few times turns a later
    // assertion into a 429.
    clearRedisRateLimiter('public-product');
});

it('creates a public link and serves the page behind it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'share_slug' => null]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->call('generateShareLink')
        ->assertSet('shareMessage', 'Public link created.');

    $slug = $product->fresh()?->share_slug;

    expect($slug)->toBeString()->and($slug)->toHaveLength(32);

    // The link is only worth anything if the page it points at answers.
    $this->get(route('product.public', ['slug' => $slug]))->assertOk();
});

it('replaces the link and kills the old one', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'share_slug' => str_repeat('a', 32)]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])->call('rotateShareLink');

    $slug = $product->fresh()?->share_slug;

    expect($slug)->not->toBe(str_repeat('a', 32));

    $this->get(route('product.public', ['slug' => str_repeat('a', 32)]))->assertNotFound();
    $this->get(route('product.public', ['slug' => (string) $slug]))->assertOk();
});

it('withdraws the link so the page stops answering', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'share_slug' => str_repeat('b', 32)]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->call('stopSharing')
        ->assertSet('shareMessage', 'Public sharing stopped. The link now returns a 404.');

    expect($product->fresh()?->share_slug)->toBeNull();

    $this->get(route('product.public', ['slug' => str_repeat('b', 32)]))->assertNotFound();
});

it('does not overwrite a link another tab just created', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'share_slug' => null]);

    $this->actingAs($user);

    $component = livewire(ProductShow::class, ['product' => $product]);

    // The other tab shares it while this component still believes it is not
    // shared. The conditional update is what stops the second write.
    Product::query()->whereKey($product->id)->update(['share_slug' => str_repeat('c', 32)]);

    $component->call('generateShareLink')
        ->assertSet('shareMessage', 'This product was already shared in another tab.');

    expect($product->fresh()?->share_slug)->toBe(str_repeat('c', 32));
});

it('acts on the link the page is showing, not on a remembered one', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'share_slug' => str_repeat('d', 32)]);

    $this->actingAs($user);

    $component = livewire(ProductShow::class, ['product' => $product]);

    // Another tab replaces the link. Livewire re-reads the product on the next
    // round trip, so this page is showing the new link by the time anyone can
    // click Stop — and stopping takes down the link they were looking at.
    Product::query()->whereKey($product->id)->update(['share_slug' => str_repeat('e', 32)]);

    $component->call('stopSharing')
        ->assertSet('shareMessage', 'Public sharing stopped. The link now returns a 404.');

    expect($product->fresh()?->share_slug)->toBeNull();
});

it('refuses to share somebody elses product', function (): void {
    $user = User::factory()->create();
    $mine = Product::factory()->create(['user_id' => $user->id]);
    $stranger = Product::factory()->create(['share_slug' => null]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $stranger])->assertForbidden();

    // And the component bound to my own product cannot reach theirs.
    expect($stranger->fresh()?->share_slug)->toBeNull()
        ->and($mine->fresh()?->share_slug)->toBeNull();
});

it('offers sharing on the product page', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $this->get(route('app.products.show', $product))
        ->assertOk()
        ->assertSee('Public sharing')
        ->assertSee('generateShareLink', escape: false);
});

it('shows the link itself once the product is shared', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'share_slug' => str_repeat('f', 32)]);

    $this->actingAs($user);

    $this->get(route('app.products.show', $product))
        ->assertOk()
        ->assertSee(route('product.public', ['slug' => str_repeat('f', 32)]), escape: false)
        ->assertSee('Stop sharing');
});
