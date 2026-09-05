<?php declare(strict_types=1);

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\UnitPriceTargetNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

function productAtTarget(User $user): Product
{
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'currency' => 'EUR',
        'unit_price_target' => '6.00',
    ]);

    Shop::factory()->create([
        'product_id' => $product->id,
        'active' => true,
        'current_in_stock' => true,
        'current_price' => '1.99',
        'pack_quantity' => '370.00',
        'pack_unit' => 'g',
    ]);

    return $product->fresh() ?? $product;
}

it('does not alert a free account on a unit price target', function (): void {
    Notification::fake();

    $product = productAtTarget(User::factory()->create());

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertNothingSent();
});

it('alerts a pro account on the same target', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    subscribeUser($user);

    $product = productAtTarget($user);

    app(DetectUnitPriceTarget::class)($product);

    Notification::assertSentTo($user, UnitPriceTargetNotification::class);
});

it('keeps the stored target of a free account so an upgrade turns it back on', function (): void {
    Notification::fake();

    $product = productAtTarget(User::factory()->create());

    app(DetectUnitPriceTarget::class)($product);

    expect($product->fresh()?->unit_price_target)->not->toBeNull();
});

it('offers a trial only to an account that never had this subscription', function (): void {
    $fresh = User::factory()->create();

    expect($fresh->qualifiesForTrial())->toBeTrue();

    subscribeUser($fresh, 'canceled', endsAt: CarbonImmutable::now()->subDay());

    // Cancelling and coming back must not hand out a second free fortnight.
    expect($fresh->fresh()?->qualifiesForTrial())->toBeFalse();
});

it('refuses checkout when no stripe price is configured', function (): void {
    config()->set('plans.stripe.pro_price_id', null);

    $this->actingAs(User::factory()->create())
        ->get('/billing/checkout')
        ->assertRedirect('/app/billing');
});

it('sends an already-pro customer back instead of selling twice', function (): void {
    config()->set('plans.stripe.pro_price_id', 'price_test');

    $user = User::factory()->create();
    subscribeUser($user);

    $this->actingAs($user)
        ->get('/billing/checkout')
        ->assertRedirect('/app/billing');
});

it('refuses to sell a second subscription to a blocked customer', function (): void {
    config()->set('plans.stripe.pro_price_id', 'price_test');

    // A lost chargeback makes isPro() false while Stripe still bills the
    // subscription. Selling again would charge them twice.
    $user = User::factory()->create(['billing_blocked_at' => now()]);

    $this->actingAs($user)
        ->get('/billing/checkout')
        ->assertRedirect('/app/billing');

    expect($user->fresh()?->stripe_checkout_session_id)->toBeNull();
});

it('sends an active subscriber back rather than starting a second checkout', function (): void {
    config()->set('plans.stripe.pro_price_id', 'price_test');

    $user = User::factory()->create();
    subscribeUser($user, 'past_due');

    $this->actingAs($user)
        ->get('/billing/checkout')
        ->assertRedirect('/app/billing');
});

it('sends a customer with no stripe account back from the portal', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/billing/portal')
        ->assertRedirect('/app/billing');
});
