<?php declare(strict_types=1);

use App\Jobs\CategoriseExistingProduct;
use App\Livewire\Billing\BillingPage;
use App\Livewire\ProAiOffer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Subscription;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
});

/**
 * @param  array<model-property<User>, mixed>  $attributes
 */
function proForOffer(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    subscribeUser($user);

    return $user->refresh();
}

test('a Pro account with AI features off is asked once, on any app page', function (): void {
    $user = proForOffer(['shop_checks' => true]);
    $this->actingAs($user);

    $this->get(route('app.dashboard'))->assertOk()->assertSeeHtml('data-test="pro-ai-offer"');

    livewire(ProAiOffer::class)->assertSet('open', false);

    expect($user->refresh()->ai_offer_shown_at)->not->toBeNull();
});

test('it offers only the features that are off', function (): void {
    $this->actingAs(proForOffer(['shop_checks' => true]));

    livewire(ProAiOffer::class)
        ->assertSet('open', true)
        ->assertSet('offered', ['categories'])
        ->assertSee('Automatic categories')
        ->assertDontSee('Searches the web');
});

test('nobody is asked who cannot use it, has both on, or was asked before', function (User $user): void {
    $this->actingAs($user);

    livewire(ProAiOffer::class)->assertSet('open', false)->assertDontSeeHtml('data-test="pro-ai-offer"');
})->with([
    'free account' => fn (): User => User::factory()->create(),
    'both on' => fn (): User => proForOffer(['shop_checks' => true, 'auto_categories' => true]),
    'asked before' => fn (): User => proForOffer(['ai_offer_shown_at' => now()->subYear()]),
]);

test('nobody is asked where the AI is not set up', function (): void {
    config()->set('services.typesafe.key', '');
    $user = proForOffer();
    $this->actingAs($user);

    livewire(ProAiOffer::class)->assertSet('open', false);
    expect($user->refresh()->ai_offer_shown_at)->toBeNull();
});

test('switch on ignores a feature the dialog did not offer, and one the plan no longer allows', function (): void {
    $user = proForOffer(['shop_checks' => true]);
    $this->actingAs($user);
    $offer = livewire(ProAiOffer::class)->set('chosen', ['categories', 'shop_checks']);

    $user->forceFill(['shop_checks' => false])->save();
    $offer->call('switchOn');
    expect($user->refresh()->shop_checks)->toBeFalse()
        ->and($user->auto_categories)->toBeTrue();

    $lapsed = proForOffer();
    $this->actingAs($lapsed);
    $dialog = livewire(ProAiOffer::class);
    Subscription::query()->where('user_id', $lapsed->id)->update(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);
    $this->actingAs($lapsed->refresh());
    $dialog->call('switchOn')->assertNotDispatched('ai-feature-switched-on');

    expect($lapsed->refresh()->auto_categories)->toBeFalse()
        ->and($lapsed->shop_checks)->toBeFalse();
});

test('switch on turns on the ticked features and starts their work', function (): void {
    Queue::fake();
    $user = proForOffer();
    Product::factory()->for($user)->create(['category' => null]);
    $this->actingAs($user);

    livewire(ProAiOffer::class)
        ->set('chosen', ['categories'])
        ->call('switchOn')
        ->assertSet('open', false)
        ->assertDispatched('ai-feature-switched-on', feature: 'categories')
        ->assertNotDispatched('ai-feature-switched-on', feature: 'shop_checks');

    $user->refresh();
    expect($user->auto_categories)->toBeTrue()
        ->and($user->shop_checks)->toBeFalse();
    Queue::assertPushed(CategoriseExistingProduct::class, 1);
});

test('not now switches nothing on, and the account is not asked again', function (): void {
    $user = proForOffer();
    $this->actingAs($user);

    livewire(ProAiOffer::class)->assertSet('open', true)->set('open', false);
    livewire(ProAiOffer::class)->assertSet('open', false);

    $user->refresh();
    expect($user->auto_categories)->toBeFalse()
        ->and($user->shop_checks)->toBeFalse();
});

test('it opens when the billing page sees the payment go through', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $offer = livewire(ProAiOffer::class)->assertSet('open', false);

    subscribeUser($user);
    $this->actingAs($user->refresh());

    $offer->dispatch('pro-started')->assertSet('open', true);
});

test('after checkout the billing page waits for Stripe, then welcomes the account to Pro', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = Livewire::withQueryParams(['checkout' => 'done'])->test(BillingPage::class)
        ->assertSeeHtml('data-test="checkout-waiting"')
        ->call('waitForPro')
        ->assertNotDispatched('pro-started')
        ->assertSet('checkoutWaits', 1);

    subscribeUser($user);
    $this->actingAs($user->refresh());

    $page->call('waitForPro')
        ->assertDispatched('pro-started')
        ->assertSeeHtml('data-test="checkout-welcome"')
        ->assertDontSeeHtml('data-test="checkout-waiting"');
});

test('the billing page stops waiting and says Pro is on its way when Stripe is slow', function (): void {
    $this->actingAs(User::factory()->create());

    $page = livewire(BillingPage::class, ['checkout' => 'done']);

    foreach (range(1, BillingPage::MAX_CHECKOUT_POLLS) as $wait) {
        $page->call('waitForPro');
    }

    $page->assertSeeHtml('data-test="checkout-slow"')->assertDontSeeHtml('data-test="checkout-waiting"');
});

test('a billing page reached without a checkout says nothing about one', function (): void {
    $this->actingAs(proForOffer());

    livewire(BillingPage::class)
        ->assertDontSeeHtml('data-test="checkout-welcome"')
        ->assertDontSeeHtml('data-test="checkout-waiting"');
});
