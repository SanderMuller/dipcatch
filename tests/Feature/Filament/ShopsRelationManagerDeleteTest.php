<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

test('the owner can remove a shop from the product', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create();

    $this->actingAs($user);

    // Regression: View-page relation managers are read-only by default,
    // which silently hides the delete action (isReadOnly() override) —
    // and an absent ShopPolicy would deny it outright.
    mountShopsRelationManager($product)
        ->assertActionVisible(TestAction::make('delete')->table($shop))
        ->callAction(TestAction::make('delete')->table($shop))
        ->assertHasNoActionErrors();

    expect(Shop::query()->whereKey($shop->id)->exists())->toBeFalse();
});

test('a note icon click mounts the edit_notes modal only when a note exists', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->for($product)->create(['notes' => 'ships free above 20']);

    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertSeeHtml('notes_indicator');
});
