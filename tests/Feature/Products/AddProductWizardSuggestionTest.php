<?php declare(strict_types=1);

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Enums\ProductCategory;
use App\Enums\PromotionDepthBand;
use App\Livewire\Products\AddProductWizard;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\UnitPriceTargetNotification;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Cashier\Subscription;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    RateLimiter::clear('shop-check:alert-suggestion:app');
});

/** Jev confident that the product goes half price, or failing when `$status` says so. */
function fakeJevAnswer(int $status = 200): void
{
    Http::fake([TypeSafeClient::ENDPOINT => $status === 200
        ? Http::response(['answers' => [TypeSafeClient::PROMOTION_DEPTH_QUESTION => ['probabilities' => [PromotionDepthBand::HalfOrMore->value => 0.9]]]])
        : Http::response([], $status)]);
}

/**
 * Pet food at one 1 kg shop: €4.00 normally, `$now` today against that
 * claimed €4.00. The category table says 20% for pet food.
 */
function suggestionWizardProduct(User $user, string $now = '4.00'): Product
{
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'category' => ProductCategory::PetFood]);
    Shop::factory()->for($product)->create([
        'url' => 'https://zooplus.nl/p/1', 'currency' => 'EUR', 'current_in_stock' => true,
        'pack_quantity' => '1000.00', 'pack_unit' => 'g',
    ])->forceFill(['current_price' => $now, 'claimed_regular_price' => $now === '4.00' ? null : '4.00'])->save();

    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

function wizardProUser(bool $optIn = true): User
{
    $user = User::factory()->create(['shop_checks' => $optIn]);
    subscribeUser($user);

    return $user;
}

function alertStep(Product $product): Testable
{
    return Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 3])
        ->test(AddProductWizard::class);
}

it('suggests an alert from the category on a free account, with the Pro teaser and no AI request', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user))
        ->assertSeeHtml('data-test="alert-suggestion"')
        ->assertSee('Pet food often goes about 20% off.')
        ->assertSee('We suggest €3.20 /kg, 20% under the normal €4.00 /kg.')
        ->assertSeeHtml('data-test="pro-teaser"')
        ->assertDontSeeHtml('wire:init="askJev"')
        // Called from the browser anyway: the server refuses it too.
        ->call('askJev');

    Http::assertNothingSent();
});

it('asks Jev on Pro with AI help on, and suggests its band', function (): void {
    fakeJevAnswer();
    $user = wizardProUser();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user))
        ->assertSeeHtml('wire:init="askJev"')
        ->call('askJev')
        ->assertSee('Products like this often go 50% off.')
        ->assertSee('We suggest €2.00 /kg');

    Http::assertSentCount(1);
});

it('asks Jev once while the product stays the same', function (): void {
    fakeJevAnswer();
    $user = wizardProUser();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user))
        ->call('askJev')
        ->call('goToStep', 2)
        ->call('goToStep', 3)
        ->call('askJev');

    Http::assertSentCount(1);
});

it('falls back to the category when Jev fails', function (): void {
    fakeJevAnswer(500);
    $user = wizardProUser();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user))
        ->call('askJev')
        ->assertSee('Pet food often goes about 20% off.');

    Http::assertSentCount(1);
});

it('points a Pro account without AI help at the switch, and asks nothing', function (): void {
    fakeJevAnswer();
    $user = wizardProUser(optIn: false);
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user))
        ->assertSeeHtml('data-test="switch-on-ai"')
        ->assertDontSeeHtml('wire:init="askJev"')
        ->call('askJev');

    Http::assertNothingSent();
});

it('shows the free view to an account that lost Pro before step 3', function (): void {
    fakeJevAnswer();
    $user = wizardProUser();
    $product = suggestionWizardProduct($user);
    Subscription::query()->where('user_id', $user->id)->update(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);
    $this->actingAs($user->refresh());

    alertStep($product)
        ->assertSeeHtml('data-test="pro-teaser"')
        ->assertDontSeeHtml('wire:init="askJev"');
});

it('saves the suggestion as a per-unit target with nothing else beside it', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $product = suggestionWizardProduct($user);
    $product->forceFill(['drop_threshold_pct' => '10.00', 'target_price' => '3.00'])->save();
    $this->actingAs($user);

    alertStep($product)
        ->call('useSuggestion')
        ->assertSet('unitPriceTarget', '3.2')
        ->assertSet('dropThresholdPct', null)
        ->call('saveAlerts')
        ->assertHasNoErrors();

    $product->refresh();

    expect((string) $product->unit_price_target)->toBe('3.2000')
        ->and($product->drop_threshold_pct)->toBeNull()
        ->and($product->target_price)->toBeNull()
        // Not met at €4.00, so nothing is latched.
        ->and($product->unit_price_notified)->toBeNull();
});

it('marks a suggested target the offer on screen already meets as notified, until the next offer', function (): void {
    fakeJevAnswer();
    Notification::fake();
    $user = User::factory()->create();
    // 25% off now: the 25% step, €3.00, which today's €3.00 meets.
    $product = suggestionWizardProduct($user, now: '3.00');
    $this->actingAs($user);

    alertStep($product)
        ->assertSee('It is at that price now')
        ->call('useSuggestion')
        ->call('saveAlerts');

    $product->refresh();
    expect((string) $product->unit_price_target)->toBe('3.0000')
        ->and((string) $product->unit_price_notified)->toBe('3.0000')
        ->and($product->unit_price_notified_at)->not->toBeNull();

    // The next check at the same price: no alert.
    app(DetectUnitPriceTarget::class)($product);
    Notification::assertNothingSent();

    // The offer ends, then comes back: that one alerts.
    $shop = $product->shops()->sole();
    $shop->forceFill(['current_price' => '4.00'])->save();
    $product->refresh()->recomputeCheapestShop();
    app(DetectUnitPriceTarget::class)($product->refresh());

    $shop->forceFill(['current_price' => '3.00'])->save();
    $product->refresh()->recomputeCheapestShop();
    app(DetectUnitPriceTarget::class)($product->refresh());

    Notification::assertSentTo($user, UnitPriceTargetNotification::class);
});

it('latches nothing for a target the person typed', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $product = suggestionWizardProduct($user, now: '3.00');
    $this->actingAs($user);

    alertStep($product)
        ->call('useSuggestion')
        ->set('unitPriceTarget', '3.1')
        ->call('saveAlerts');

    expect($product->refresh()->unit_price_notified)->toBeNull();
});

it('keeps a lower latch a reopened product already has', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $product = suggestionWizardProduct($user, now: '3.00');
    $product->forceFill(['unit_price_target' => '3.0000'])->save();
    $product->forceFill(['unit_price_notified' => '2.5000', 'unit_price_notified_at' => now()->subWeek()])->save();
    $this->actingAs($user);

    alertStep($product)
        ->call('useSuggestion')
        ->call('saveAlerts');

    expect((string) $product->refresh()->unit_price_notified)->toBe('2.5000');
});

it('steps the card aside for a person setting their own alert', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user))
        ->call('setOwn')
        ->assertDontSeeHtml('data-test="alert-suggestion"')
        ->assertSeeHtml('data-test="wizard-alerts"');
});

it('explains a product with no pack size, and offers no target', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['category' => ProductCategory::PetFood]);
    Shop::factory()->for($product)->create(['pack_quantity' => null, 'pack_unit' => null, 'current_price' => '4.00']);
    $this->actingAs($user);

    alertStep($product->refresh())
        ->assertSee('We could not read a pack size')
        ->assertDontSeeHtml('data-test="use-suggestion"');
});

it('keeps the pre-latch for the suggestion taken, when Jev answers after it', function (): void {
    fakeJevAnswer();
    $user = wizardProUser();
    // 25% off now: the evidence suggests €3.00, which today's €3.00 meets.
    $product = suggestionWizardProduct($user, now: '3.00');
    $this->actingAs($user);

    alertStep($product)
        ->call('useSuggestion')
        ->assertSet('unitPriceTarget', '3')
        // Jev's half-price band moves the card to €2.00; the fields keep €3.00.
        ->call('askJev')
        ->assertSee('We suggest €2.00 /kg')
        ->call('saveAlerts');

    $product->refresh();

    expect((string) $product->unit_price_target)->toBe('3.0000')
        ->and((string) $product->unit_price_notified)->toBe('3.0000');
});

it('asks Jev again once the product changed, and ignores the old answer meanwhile', function (): void {
    fakeJevAnswer();
    $user = wizardProUser();
    $product = suggestionWizardProduct($user);
    $this->actingAs($user);

    $wizard = alertStep($product)
        ->call('askJev')
        ->assertSee('Products like this often go 50% off.');

    Shop::factory()->for($product)->create(['url' => 'https://medpets.nl/p/1', 'current_price' => '4.20', 'pack_quantity' => '1000.00', 'pack_unit' => 'g']);

    $wizard->call('goToStep', 2)
        ->call('goToStep', 3)
        ->assertSee('Pet food often goes about 20% off.')
        ->call('askJev');

    Http::assertSentCount(2);
});

it('empties the target the card filled in when the person sets their own', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user))
        ->call('useSuggestion')
        ->call('setOwn')
        ->assertSet('unitPriceTarget', null)
        ->call('showSuggestion')
        ->assertSeeHtml('data-test="alert-suggestion"');
});

it('keeps an alert the product already had when the person sets their own', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $product = suggestionWizardProduct($user);
    $product->forceFill(['unit_price_target' => '2.5000'])->save();
    $this->actingAs($user);

    alertStep($product)
        ->call('setOwn')
        ->assertSet('unitPriceTarget', '2.5');
});

it('confirms the alert it set, for a screen reader', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user))
        ->call('useSuggestion')
        ->assertSet('status', 'Alert set to 3.2.')
        ->assertSeeHtml('role="status"');
});

it('refuses a chosen target set from the browser', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user))->set('chosenTarget', '3.2');
})->throws(CannotUpdateLockedPropertyException::class);

it('explains each kind of suggestion', function (string $category, string $now, ?string $claim, ?string $label, string $sentence, bool $offersTarget): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'category' => $category === '' ? null : ProductCategory::from($category)]);
    Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1', 'currency' => 'EUR', 'current_in_stock' => true,
        'pack_quantity' => '1000.00', 'pack_unit' => $category === 'food.alcohol' ? 'ml' : 'g',
    ])->forceFill(['current_price' => $now, 'claimed_regular_price' => $claim, 'promotion_label' => $label])->save();
    $product->refresh()->recomputeCheapestShop();
    $this->actingAs($user);

    $wizard = alertStep($product->refresh())->assertSee($sentence);

    $offersTarget
        ? $wizard->assertSeeHtml('data-test="use-suggestion"')
        : $wizard->assertDontSeeHtml('data-test="use-suggestion"');
})->with([
    'a promotion on now' => ['food.pantry', '3.00', '4.00', null, 'ah.nl has this 25% off now.', true],
    'the alcohol limit' => ['food.alcohol', '0.60', '1.20', null, 'Alcohol can go at most 25% off in the Netherlands.', true],
    'no normal price' => ['home.small_appliances', '70.00', '100.00', null, 'We could not tell its normal price', false],
    'no sign of sales' => ['', '4.00', null, null, 'We have no sign that this product goes on sale', false],
    'on offer with no normal price' => ['home.small_appliances', '70.00', '100.00', null, 'It is on offer now, so for its first weeks the default counts from the offer price.', false],
]);

it('says a fixed-price product gets the default alert', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(['answers' => [TypeSafeClient::PROMOTION_DEPTH_QUESTION => ['probabilities' => [PromotionDepthBand::Fixed->value => 0.9]]]])]);
    $user = wizardProUser();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user, now: '3.00'))
        ->call('askJev')
        ->assertSee('This product has a fixed price')
        ->assertDontSeeHtml('data-test="use-suggestion"');
});

it('stops using Jev\'s answer once the owner has no AI help any more', function (): void {
    fakeJevAnswer();
    $user = wizardProUser();
    $product = suggestionWizardProduct($user);
    $this->actingAs($user);

    $wizard = alertStep($product)
        ->call('askJev')
        ->assertSee('Products like this often go 50% off.');

    $user->forceFill(['shop_checks' => false])->save();

    $wizard->call('goToStep', 2)
        ->call('goToStep', 3)
        ->assertSee('Pet food often goes about 20% off.')
        ->assertDontSee('Products like this often go 50% off.');
});
