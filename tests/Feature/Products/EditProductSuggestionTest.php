<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Enums\PromotionDepthBand;
use App\Livewire\Products\EditProduct;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    RateLimiter::clear('shop-check:alert-suggestion:app');
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(['answers' => [TypeSafeClient::PROMOTION_DEPTH_QUESTION => ['probabilities' => [PromotionDepthBand::HalfOrMore->value => 0.9]]]])]);
});

/**
 * Pet food at one 1 kg shop: €4.00 normally, `$now` today against that
 * claimed €4.00, with `$unitTarget` already set. The category table says
 * 20% for pet food.
 */
function editSuggestionProduct(User $user, string $now = '4.00', ?string $unitTarget = null): Product
{
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'category' => ProductCategory::PetFood, 'unit_price_target' => $unitTarget]);
    Shop::factory()->for($product)->create([
        'url' => 'https://zooplus.nl/p/1', 'currency' => 'EUR', 'current_in_stock' => true,
        'pack_quantity' => '1000.00', 'pack_unit' => 'g',
    ])->forceFill(['current_price' => $now, 'claimed_regular_price' => $now === '4.00' ? null : '4.00'])->save();

    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

it('suggests an alert as a choice in the price list while the product has no price per kilo target', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => editSuggestionProduct($user)])
        ->assertSeeHtml('data-test="unit-target-suggestion"')
        ->assertSeeHtml('suggestedUnit: 3.2,')
        ->assertSeeHtml('wire:click="useSuggestion"')
        ->assertSeeInOrder(['Suggested alert', '20% under normal', 'Pet food often goes about 20% off.', 'The normal price is €4.00 /kg.'])
        ->assertDontSeeHtml('data-test="alert-suggestion"')
        ->assertDontSee('Set my own')
        ->assertDontSeeHtml('wire:init="askJev"');

    Http::assertNothingSent();
});

it('leaves the suggestion out once the product has a price per kilo target', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => editSuggestionProduct($user, unitTarget: '3.5000')])
        ->assertDontSeeHtml('data-test="unit-target-suggestion"');
});

it('fills in the suggestion without saving it, and saves it on Save', function (): void {
    $user = User::factory()->create();
    $product = editSuggestionProduct($user);
    $this->actingAs($user);

    $form = livewire(EditProduct::class, ['product' => $product])
        ->call('useSuggestion')
        ->assertSet('unitPriceTarget', '3.2')
        ->assertSet('status', 'Alert set to 3.2. Save changes to keep it.');

    expect($product->refresh()->unit_price_target)->toBeNull();

    $form->call('save')->assertHasNoErrors();

    expect((string) $product->refresh()->unit_price_target)->toBe('3.2000')
        ->and($product->unit_price_notified)->toBeNull();
});

it('marks a kept suggestion the price already meets as notified, as the wizard does', function (): void {
    $user = User::factory()->create();
    // 25% off now: the evidence suggests €3.00, which today's €3.00 meets.
    $product = editSuggestionProduct($user, now: '3.00');
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => $product])
        ->call('useSuggestion')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->unit_price_notified)->not->toBeNull();
});

it('asks Jev on Pro with AI help on, and suggests its band', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $this->actingAs($user);

    livewire(EditProduct::class, ['product' => editSuggestionProduct($user)])
        ->assertSeeHtml('data-test="unit-target-suggestion-checking"')
        ->assertSeeHtml('wire:init="askJev"')
        ->assertSeeHtml('suggestedUnit: null,')
        ->call('askJev')
        ->assertDontSeeHtml('data-test="unit-target-suggestion-checking"')
        ->assertSeeHtml('suggestedUnit: 2,')
        ->assertSee('Products like this often go 50% off.');

    Http::assertSentCount(1);
});

it('shows the suggestion rather than a checking card when a shop changed while Jev answered', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = editSuggestionProduct($user);
    $this->actingAs($user);
    Http::fake([TypeSafeClient::ENDPOINT => function () use ($product) {
        $product->shops()->update(['gtin' => '8710400000001']);

        return Http::response(['answers' => [TypeSafeClient::PROMOTION_DEPTH_QUESTION => ['probabilities' => [PromotionDepthBand::HalfOrMore->value => 0.9]]]]);
    }]);

    livewire(EditProduct::class, ['product' => $product])
        ->call('askJev')
        ->assertDontSeeHtml('data-test="alert-suggestion-checking"')
        ->assertSee('Pet food often goes about 20% off.');
});

it('shows the suggestion rather than a checking card when a shop changed during a failed request', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = editSuggestionProduct($user);
    $this->actingAs($user);
    Http::fake([TypeSafeClient::ENDPOINT => function () use ($product) {
        $product->shops()->update(['gtin' => '8710400000001']);

        return Http::response([], 500);
    }]);

    livewire(EditProduct::class, ['product' => $product])
        ->call('askJev')
        ->assertDontSeeHtml('data-test="alert-suggestion-checking"')
        ->assertSee('Pet food often goes about 20% off.');
});

it('offers shop checks under the suggestion to Pro with them off, and Pro itself to a free account', function (): void {
    $pro = User::factory()->create(['shop_checks' => false]);
    subscribeUser($pro);
    $this->actingAs($pro);
    livewire(EditProduct::class, ['product' => editSuggestionProduct($pro)])
        ->assertSeeHtml('data-place="alert"')
        ->assertDontSeeHtml('data-test="pro-teaser"');

    app()->forgetScopedInstances();
    $free = User::factory()->create();
    $this->actingAs($free);
    livewire(EditProduct::class, ['product' => editSuggestionProduct($free)])
        ->assertSeeHtml('data-test="pro-teaser"')
        ->assertDontSeeHtml('data-place="alert"');
});
