<?php declare(strict_types=1);

it('serves the support page', function (): void {
    $this->get('/support')
        ->assertOk()
        ->assertSee('Support')
        ->assertSee('Plans, payment and cancelling');
});

it('serves the terms page at the address Stripe was given', function (): void {
    // /terms-of-service, not /terms: the path is what the Stripe business
    // profile points at, so the route name and the URL differ on purpose.
    $this->get('/terms-of-service')
        ->assertOk()
        ->assertSee('Terms of service')
        ->assertSee('Dutch law applies', escape: false);
});

it('redirects the conventional privacy path to the one privacy page', function (): void {
    // One canonical page rather than two copies of the same text.
    $this->get('/privacy-policy')
        ->assertRedirect(route('privacy'))
        ->assertStatus(301);
});

it('carries the locale through the privacy redirect', function (): void {
    $this->get('/privacy-policy?lang=nl')->assertRedirect(route('privacy', ['lang' => 'nl']));
});

it('shows the support email when one is configured', function (): void {
    config()->set('site.contact_email', 'help@example.test');

    $this->get('/support')
        ->assertOk()
        ->assertSee('mailto:help@example.test', escape: false)
        ->assertSee('"email":"help@example.test"', escape: false)
        ->assertSee('Request a shop', escape: false)
        ->assertSee('Send a product URL if a paste does not pick up the price', escape: false);
});

it('says where to find the address when no email is configured', function (): void {
    // A support page that renders an empty mailto is worse than one that
    // tells the reader where to look.
    config()->set('site.contact_email');

    $this->get('/support')
        ->assertOk()
        ->assertDontSee('mailto:', escape: false)
        ->assertSee('The address is on the account page inside the app.', escape: false);
});

it('states the trial length the app actually offers', function (): void {
    config()->set('plans.stripe.trial_days', 14);

    $this->get('/terms-of-service')->assertOk()->assertSee('A 14-day trial is available once per account', escape: false);

    config()->set('plans.stripe.trial_days', 0);

    // No trial configured, no promise of one.
    $this->get('/terms-of-service')->assertOk()->assertDontSee('trial is available once per account', escape: false);
});

it('renders both pages in Dutch', function (): void {
    $this->get('/support?lang=nl')->assertOk()->assertSee('Contact opnemen', escape: false);
    $this->get('/terms-of-service?lang=nl')->assertOk()->assertSee('Algemene voorwaarden', escape: false);
});

it('links the pages from every marketing footer', function (): void {
    foreach (['/', '/pricing', '/privacy'] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertSee(route('support'), escape: false)
            ->assertSee(route('terms'), escape: false);
    }
});
