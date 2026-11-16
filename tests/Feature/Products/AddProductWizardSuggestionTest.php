<?php declare(strict_types=1);

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Enums\ProductCategory;
use App\Enums\PromotionDepthBand;
use App\Livewire\AiFeaturePrompt;
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
        ->assertSeeHtml('data-test="unit-target-suggestion"')
        ->assertSeeHtml('suggestedUnit: 3.2,')
        ->assertSeeHtml('wire:click="useSuggestion"')
        ->assertSeeInOrder(['Suggested alert', '20% under normal', 'Pet food often goes about 20% off.', 'The normal price is €4.00 /kg.'])
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

    $product = suggestionWizardProduct($user);

    alertStep($product)
        ->assertSeeHtml('wire:init="askJev"')
        // Until Jev answers, the row shows no numbers that could still change.
        ->assertSeeHtml('data-test="unit-target-suggestion-checking"')
        ->assertSeeHtml('suggestedUnit: null,')
        ->assertDontSee('under normal')
        ->call('askJev')
        ->assertDontSeeHtml('data-test="unit-target-suggestion-checking"')
        ->assertSeeHtml('suggestedUnit: 2,')
        ->assertSee('Products like this often go 50% off.')
        ->assertSee('50% under normal')
        ->assertDontSeeHtml('data-test="pro-teaser"');

    // A reload reads the answer back instead of asking again.
    alertStep($product)
        ->assertDontSeeHtml('wire:init="askJev"')
        ->assertSee('50% under normal');

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
        ->assertSeeLivewire(AiFeaturePrompt::class)
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

it('saves the suggestion as a per-unit target', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $product = suggestionWizardProduct($user);
    $this->actingAs($user);

    alertStep($product)
        ->call('useSuggestion')
        ->assertSet('unitPriceTarget', '3.2')
        ->call('saveAlerts')
        ->assertHasNoErrors();

    $product->refresh();

    expect((string) $product->unit_price_target)->toBe('3.2000')
        ->and($product->unit_price_target_unit)->toBe('g')
        // Not met at €4.00, so nothing is latched.
        ->and($product->unit_price_notified)->toBeNull();
});

it('marks a suggested target the offer on screen already meets as notified, until the next offer', function (): void {
    fakeJevAnswer();
    Notification::fake();
    $user = User::factory()->create();
    // 20% off now, the usual pet-food depth: €3.20, which today's €3.20 meets.
    $product = suggestionWizardProduct($user, now: '3.20');
    $this->actingAs($user);

    alertStep($product)
        ->assertSee('It is at that price now')
        ->call('useSuggestion')
        ->call('saveAlerts');

    $product->refresh();
    expect((string) $product->unit_price_target)->toBe('3.2000')
        ->and((string) $product->unit_price_notified)->toBe('3.2000')
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
    $product = suggestionWizardProduct($user, now: '3.20');
    $product->forceFill(['unit_price_target' => '3.2000'])->save();
    $product->forceFill(['unit_price_notified' => '2.5000', 'unit_price_notified_at' => now()->subWeek()])->save();
    $this->actingAs($user);

    alertStep($product)
        ->call('useSuggestion')
        ->call('saveAlerts');

    expect((string) $product->refresh()->unit_price_notified)->toBe('2.5000');
});

it('explains a product with no pack size, and offers no target', function (bool $pro): void {
    fakeJevAnswer();
    $user = $pro ? wizardProUser() : User::factory()->create();
    $product = Product::factory()->for($user)->create(['category' => ProductCategory::PetFood]);
    Shop::factory()->for($product)->create(['pack_quantity' => null, 'pack_unit' => null, 'current_price' => '4.00']);
    $this->actingAs($user);

    alertStep($product->refresh())
        ->assertSee('We could not read a pack size')
        ->assertDontSeeHtml('data-test="unit-target-suggestion"')
        ->assertDontSeeHtml('wire:init="askJev"');

    Http::assertNothingSent();
})->with(['free' => [false], 'Pro with AI help, which has nothing to ask' => [true]]);

it('keeps the pre-latch for the suggestion taken, when Jev answers after it', function (): void {
    fakeJevAnswer();
    $user = wizardProUser();
    // 20% off now: the evidence suggests €3.20, which today's €3.20 meets.
    $product = suggestionWizardProduct($user, now: '3.20');
    $this->actingAs($user);

    alertStep($product)
        ->call('useSuggestion')
        ->assertSet('unitPriceTarget', '3.2')
        // Jev's half-price band moves the suggestion to €2.00; the fields keep €3.20.
        ->call('askJev')
        ->assertSee('50% under normal')
        ->call('saveAlerts');

    $product->refresh();

    expect((string) $product->unit_price_target)->toBe('3.2000')
        ->and((string) $product->unit_price_notified)->toBe('3.2000');
});

it('asks Jev again once the product changed, and shows no old answer meanwhile', function (): void {
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
        ->assertSeeHtml('data-test="unit-target-suggestion-checking"')
        ->assertDontSee('Products like this often go 50% off.')
        ->call('askJev')
        ->assertSee('Products like this often go 50% off.');

    Http::assertSentCount(2);
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
        ? $wizard->assertSeeHtml('data-test="unit-target-suggestion"')
        : $wizard->assertDontSeeHtml('data-test="unit-target-suggestion"');
})->with([
    'a promotion on now' => ['food.pantry', '3.00', '4.00', null, 'Albert Heijn has this 25% off now.', true],
    // Pantry usually goes 35% off: halfway to 50% now is the 40% step.
    'halfway to a deeper offer' => ['food.pantry', '2.00', '4.00', null, 'We suggest a price halfway between that and its usual offers.', true],
    'the alcohol limit' => ['food.alcohol', '0.60', '1.20', null, 'Alcohol can go at most 25% off in the Netherlands.', true],
    'no normal price' => ['home.small_appliances', '70.00', '100.00', null, 'We could not tell its normal price', false],
    'no sign of sales' => ['', '4.00', null, null, 'We have no sign that this product goes on sale', false],
    'on offer with no normal price' => ['home.small_appliances', '70.00', '100.00', null, 'It is on offer now, so for its first weeks that counts from the offer price.', false],
]);

it('names a shop it leaves out of the normal price for being far cheaper', function (): void {
    fakeJevAnswer();
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'category' => ProductCategory::PaperDisposables]);

    foreach (['123schoon.nl' => '11.95', 'ah.nl' => '17.99', 'jumbo.com' => '17.49'] as $host => $price) {
        Shop::factory()->for($product)->create([
            'url' => "https://www.{$host}/p/1", 'host' => $host, 'currency' => 'EUR', 'current_in_stock' => true,
            'pack_quantity' => '16.00', 'pack_unit' => 'piece',
        ])->forceFill(['current_price' => $price])->save();
    }

    $product->refresh()->recomputeCheapestShop();
    $this->actingAs($user);

    alertStep($product->refresh())->assertSee('123schoon.nl is normally far cheaper than the other shops, so its price does not count as the normal one.');
});

it('says a fixed-price product gets the default alert', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(['answers' => [TypeSafeClient::PROMOTION_DEPTH_QUESTION => ['probabilities' => [PromotionDepthBand::Fixed->value => 0.9]]]])]);
    $user = wizardProUser();
    $this->actingAs($user);

    alertStep(suggestionWizardProduct($user, now: '3.00'))
        ->call('askJev')
        ->assertSee('This product has a fixed price')
        ->assertDontSeeHtml('data-test="unit-target-suggestion"');
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

it('asks which pack the person buys, also when the shops sell one size', function (string $secondPack): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = suggestionWizardProduct($user);
    Shop::factory()->for($product)->create([
        'url' => 'https://medpets.nl/p/1', 'currency' => 'EUR', 'current_in_stock' => true,
        'pack_quantity' => $secondPack, 'pack_unit' => 'g',
    ])->forceFill(['current_price' => '4.20'])->save();
    $product->refresh()->recomputeCheapestShop();

    $wizard = alertStep($product->refresh());

    $wizard->assertSee('Alert me when it costs');
    $wizard->assertSee('Which pack do you buy?');
})->with([
    'the same size at both shops' => ['1000.00'],
    'two sizes' => ['2000.00'],
]);
