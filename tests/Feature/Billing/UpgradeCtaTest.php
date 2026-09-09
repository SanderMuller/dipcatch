<?php declare(strict_types=1);

use App\Models\User;

it('remembers the billing page while a stranger registers', function (): void {
    // The whole point of the route: Fortify's register, login and
    // email-verification responses all redirect through intended(), so the
    // reason someone signed up survives the signup.
    $this->get(route('upgrade'))
        ->assertRedirect(route('register'))
        ->assertSessionHas('url.intended', url('/app/billing'));
});

it('sends a signed-in free account straight to checkout', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('upgrade'))->assertRedirect(route('billing.checkout'));
});

it('sends a subscriber to the page that manages the subscription', function (): void {
    configureStripe();

    $user = User::factory()->create();
    subscribeUser($user);

    $this->actingAs($user);

    // Not a second checkout: this account is already billed.
    $this->get(route('upgrade'))->assertRedirect('/app/billing');
});

it('offers Pro on the pricing page through the one upgrade link', function (): void {
    // Without this the card reads "Coming soon" and the assertion would
    // pass or fail for the wrong reason.
    configureStripe();

    $this->get(route('pricing'))
        ->assertOk()
        ->assertSee(route('upgrade'), escape: false);
});

it('points a subscriber at their plan rather than at another upgrade', function (): void {
    configureStripe();

    $user = User::factory()->create();
    subscribeUser($user);

    $this->actingAs($user);

    $this->get(route('pricing'))
        ->assertOk()
        ->assertSee('Your plan')
        ->assertDontSee(route('upgrade'), escape: false);
});
