<?php declare(strict_types=1);

use App\Billing\BillingGate;
use App\Filament\Admin\Resources\Disputes\DisputeResource;
use App\Filament\Admin\Resources\Subscribers\SubscriberResource;
use App\Filament\Admin\Widgets\RevenueOverviewWidget;
use App\Filament\App\Pages\Billing;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

it('keeps the shop shut until every Stripe setting is present', function (string $missing): void {
    configureStripe();
    config()->set($missing, null);

    expect(BillingGate::isOpen())->toBeFalse();
})->with([
    'cashier.key',
    'cashier.secret',
    'cashier.webhook.secret',
    'plans.stripe.pro_price_id',
]);

it('opens the shop once Stripe is fully configured', function (): void {
    configureStripe();

    expect(BillingGate::isOpen())->toBeTrue()
        ->and(BillingGate::missing())->toBe([]);
});

it('stays shut when the switch says so, however complete Stripe is', function (): void {
    configureStripe();
    config()->set('plans.enabled', false);

    expect(BillingGate::isOpen())->toBeFalse()
        // Still worth telling the owner that Stripe itself is ready.
        ->and(BillingGate::isConfigured())->toBeTrue()
        ->and(BillingGate::missing())->toBe([]);
});

it('names what is missing', function (): void {
    config()->set('cashier.key', null);
    config()->set('cashier.secret', null);
    config()->set('cashier.webhook.secret', null);
    config()->set('plans.stripe.pro_price_id', null);

    expect(BillingGate::missing())->toBe([
        'STRIPE_KEY',
        'STRIPE_SECRET',
        'STRIPE_WEBHOOK_SECRET',
        'STRIPE_PRICE_PRO_MONTHLY',
    ]);
});

it('offers no upgrade on the billing page while the shop is shut', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(Billing::class)
        ->assertSee('Pro is not on sale yet')
        ->assertDontSee('Upgrade to Pro')
        ->assertDontSee('Start 14-day trial')
        // Nor does it advertise what cannot be bought.
        ->assertDontSee('What Pro adds');
});

it('offers the upgrade once the shop opens', function (): void {
    configureStripe();

    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    livewire(Billing::class)
        ->assertSee('Start 14-day trial')
        ->assertDontSee('Pro is not on sale yet');
});

it('refuses checkout while the shop is shut', function (): void {
    // A price on its own is not enough to sell: a payment with no webhook
    // secret would never become a subscription.
    config()->set('plans.stripe.pro_price_id', 'price_1');
    config()->set('cashier.webhook.secret', null);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/billing/checkout')
        ->assertRedirect('/app/billing');

    expect($user->fresh()?->stripe_checkout_session_id)->toBeNull();
});

it('says coming soon on the public pricing page', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee('Coming soon')
        ->assertDontSee('Start with Pro')
        // The free plan is real and still sells itself.
        ->assertSee('20 products');
});

it('hides the admin billing screens until Stripe exists', function (): void {
    expect(SubscriberResource::shouldRegisterNavigation())->toBeFalse()
        ->and(DisputeResource::shouldRegisterNavigation())->toBeFalse()
        ->and(RevenueOverviewWidget::canView())->toBeFalse();

    configureStripe();

    expect(SubscriberResource::shouldRegisterNavigation())->toBeTrue()
        ->and(DisputeResource::shouldRegisterNavigation())->toBeTrue()
        ->and(RevenueOverviewWidget::canView())->toBeTrue();
});

it('leaves a hand-granted trial working with the shop shut', function (): void {
    // The gate is about selling, not about entitlements.
    $user = User::factory()->create(['trial_ends_at' => now()->addDays(30)]);

    expect(BillingGate::isOpen())->toBeFalse()
        ->and($user->isPro())->toBeTrue();
});
