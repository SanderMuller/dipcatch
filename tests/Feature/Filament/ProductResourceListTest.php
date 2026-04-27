<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

test('list page only shows products owned by the current user', function (): void {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $mine = Product::factory()->count(2)->for($me)->create();
    $theirs = Product::factory()->count(3)->for($other)->create();

    $this->actingAs($me);

    livewire(ListProducts::class)
        ->assertCanSeeTableRecords($mine)
        ->assertCanNotSeeTableRecords($theirs);
});

test('product list page is reachable for an authenticated user', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(ProductResource::getUrl('index'))->assertOk();
});

test('viewing another user s product directly returns 404 (scoped query hides it)', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $product = Product::factory()->for($owner)->create();

    $this->actingAs($intruder);

    // ProductResource::getEloquentQuery() scopes to auth()->id(), so the
    // intruder's request never resolves the model → 404 (better than 403:
    // doesn't even leak that the record exists). The policy is the second
    // line of defense if the scope is ever bypassed (see policy test below).
    $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertNotFound();
});

test('ProductPolicy denies cross-user access (defense-in-depth backstop)', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $product = Product::factory()->for($owner)->create();

    expect($intruder->can('view', $product))->toBeFalse()
        ->and($intruder->can('update', $product))->toBeFalse()
        ->and($intruder->can('delete', $product))->toBeFalse()
        ->and($owner->can('view', $product))->toBeTrue();
});

test('owner can view their own product page', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();

    $this->actingAs($user);

    $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk();
});

test('list page is gated to authenticated users', function (): void {
    $this->get(ProductResource::getUrl('index'))->assertRedirect(route('login'));
});
