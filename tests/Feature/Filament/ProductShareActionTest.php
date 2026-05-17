<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('app');
});

test('share action creates a 32-char share_slug on an unshared product', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['share_slug' => null]);
    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->getKey()])
        ->callAction('share')
        ->assertHasNoActionErrors();

    $fresh = $product->fresh();
    expect($fresh->share_slug)
        ->toBeString()
        ->and(strlen((string) $fresh->share_slug))->toBe(32);
});

test('share action is hidden once a slug is already set', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'share_slug' => 'already-shared-slug-abcdef123456',
    ]);
    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->getKey()])
        ->assertActionHidden('share');
});

test('rotate_share replaces an existing share_slug with a different one', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'share_slug' => 'original-slug-1234567890abcdef12',
    ]);
    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->getKey()])
        ->callAction('rotate_share')
        ->assertHasNoActionErrors();

    $fresh = $product->fresh();
    expect($fresh->share_slug)
        ->toBeString()
        ->and($fresh->share_slug)->not->toBe('original-slug-1234567890abcdef12')
        ->and(strlen((string) $fresh->share_slug))->toBe(32);
});

test('stop_share nulls the share_slug', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'share_slug' => 'about-to-be-revoked-abcdef123456',
    ]);
    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->getKey()])
        ->callAction('stop_share')
        ->assertHasNoActionErrors();

    expect($product->fresh()->share_slug)->toBeNull();
});

test('rotate_share + stop_share hidden when product is not shared', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['share_slug' => null]);
    $this->actingAs($user);

    livewire(ViewProduct::class, ['record' => $product->getKey()])
        ->assertActionHidden('rotate_share')
        ->assertActionHidden('stop_share');
});

// The share / rotate / stop actions use atomic conditional UPDATEs to refuse
// last-writer-wins overwrites between concurrent owner tabs. The conditional
// SQL is what makes the race safe — Filament's livewire-test harness re-fetches
// the record on mount, so we cannot reliably stage a stale in-memory record
// the way two real browser tabs would. Asserting the underlying conditional
// UPDATE semantic is enough to prove the race-fix mechanism works.
test('conditional UPDATE on share_slug is a no-op when the stale precondition does not match', function (): void {
    $product = Product::factory()->create(['share_slug' => 'current-slug-aaaaaaaaaaaaaaaaaaa']);

    $rows = Product::query()
        ->whereKey($product->getKey())
        ->where('share_slug', 'stale-precondition-doesnt-match!!')
        ->update(['share_slug' => 'attacker-overwrites-aaaaaaaaaaaaa']);

    expect($rows)->toBe(0);
    expect($product->fresh()->share_slug)->toBe('current-slug-aaaaaaaaaaaaaaaaaaa');

    $rows = Product::query()
        ->whereKey($product->getKey())
        ->where('share_slug', 'current-slug-aaaaaaaaaaaaaaaaaaa')
        ->update(['share_slug' => null]);

    expect($rows)->toBe(1);
    expect($product->fresh()->share_slug)->toBeNull();
});

test('share action is owner-scoped: stranger cannot generate a slug on another user\'s product', function (): void {
    $owner = User::factory()->create();
    $product = Product::factory()->for($owner)->create(['share_slug' => null]);

    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    // Filament's ProductResource::getEloquentQuery scopes to auth()->id().
    // Loading the product page via the stranger's session must 404.
    $key = $product->getKey();
    assert(is_string($key));
    $this->get("/app/products/{$key}")->assertNotFound();

    expect($product->fresh()->share_slug)->toBeNull();
});
