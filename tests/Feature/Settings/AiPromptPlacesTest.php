<?php declare(strict_types=1);

use App\Livewire\AiFeaturePrompt;
use App\Livewire\DashboardSuggestedShops;
use App\Livewire\Products\AddProductWizard;
use App\Livewire\Products\EditProduct;
use App\Livewire\Products\ProductShow;
use App\Livewire\Suggestions\ShopSuggestions;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    config()->set('services.serper.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    app()->forgetScopedInstances();
});

/**
 * @param  array<model-property<User>, mixed>  $attributes
 */
function placesProUser(array $attributes = []): User
{
    $user = User::factory()->create(['ai_offer_shown_at' => now(), ...$attributes]);
    subscribeUser($user);

    return $user->refresh();
}

/**
 * A product at one shop with its pack size stated, and `$more` shops after it.
 *
 * @param  list<array<model-property<Shop>, mixed>>  $more
 */
function placesProduct(User $user, string $currency = 'EUR', array $more = []): Product
{
    $product = Product::factory()->for($user)->create(['currency' => $currency, 'category' => null]);
    Shop::factory()->for($product)->create(['url' => 'https://a.test/p/1', 'currency' => $currency, 'current_price' => '4.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g']);

    foreach ($more as $index => $attributes) {
        Shop::factory()->for($product)->create(['url' => "https://b{$index}.test/p/1", 'currency' => $currency, 'current_price' => '4.20', ...$attributes]);
    }

    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

test('the shop suggestions offer the web search where it could run', function (): void {
    $user = placesProUser();
    $this->actingAs($user);

    livewire(ShopSuggestions::class, ['product' => placesProduct($user)])->assertSeeHtml('data-place="shop_suggestions"');

    app()->forgetScopedInstances();
    livewire(ShopSuggestions::class, ['product' => placesProduct($user, currency: 'SEK')])->assertDontSeeLivewire(AiFeaturePrompt::class);

    app()->forgetScopedInstances();
    $user->forceFill(['shop_checks' => true])->save();
    livewire(ShopSuggestions::class, ['product' => placesProduct($user)])->assertDontSeeLivewire(AiFeaturePrompt::class);
});

test('the wizard\'s shops step offers the web search to a Pro account with shop checks off', function (): void {
    $user = placesProUser();
    $this->actingAs($user);
    $product = placesProduct($user);

    Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 2])
        ->test(AddProductWizard::class)
        ->assertSeeHtml('data-place="wizard_shops"');

    app()->forgetScopedInstances();
    $abroad = placesProduct($user, currency: 'SEK');
    Livewire::withQueryParams(['product' => (string) $abroad->id, 'step' => 2])
        ->test(AddProductWizard::class)
        ->assertDontSeeHtml('data-place="wizard_shops"');
});

test('the dashboard suggestions offer the web search to an account with a product it could search for', function (): void {
    $user = placesProUser();
    $this->actingAs($user);
    placesProduct($user, currency: 'SEK');

    Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class)->assertDontSeeLivewire(AiFeaturePrompt::class);

    placesProduct($user);
    app()->forgetScopedInstances();

    Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class)->assertSeeHtml('data-place="dashboard_suggestions"');
});

test('the product page offers the check where a shop leaves its pack size in doubt', function (): void {
    // The suggestions panel comes first on the page and would take the one shop-check prompt.
    $user = placesProUser(['ai_prompt_dismissals' => ['shop_suggestions' => now()->toIso8601String()]]);
    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => placesProduct($user, more: [['pack_quantity' => null, 'pack_unit' => null]])])
        ->assertSeeHtml('data-place="pack_size"');

    app()->forgetScopedInstances();
    livewire(ProductShow::class, ['product' => placesProduct($user, more: [['pack_quantity' => '500.00', 'pack_unit' => 'g']])])
        ->assertDontSeeHtml('data-place="pack_size"');
});

test('editing a product without a category offers automatic categories to Pro only', function (): void {
    $pro = placesProUser();
    $this->actingAs($pro);
    livewire(EditProduct::class, ['product' => placesProduct($pro)])->assertSeeHtml('data-place="product_category"');

    app()->forgetScopedInstances();
    $free = User::factory()->create();
    $this->actingAs($free);
    livewire(EditProduct::class, ['product' => placesProduct($free)])->assertDontSeeLivewire(AiFeaturePrompt::class);
});

test('an owner abroad is offered the web search for a product in the currency of their country', function (): void {
    $user = placesProUser(['country' => 'se']);
    $this->actingAs($user);

    livewire(ShopSuggestions::class, ['product' => placesProduct($user, currency: 'SEK')])->assertSeeHtml('data-place="shop_suggestions"');

    app()->forgetScopedInstances();
    livewire(ShopSuggestions::class, ['product' => placesProduct($user)])->assertDontSeeLivewire(AiFeaturePrompt::class);
});
