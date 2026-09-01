<?php declare(strict_types=1);

use App\Models\User;

test('the homepage carries SEO and sharing meta', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta name="description"', escape: false)
        ->assertSee('property="og:title"', escape: false)
        ->assertSee('rel="canonical"', escape: false)
        ->assertSee('Supermarket price alerts for the Netherlands');
});

test('guests see the supported shops, a bottom call to action, and the footer links', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Works with')
        ->assertSee('ah.nl')
        ->assertSee('Stop checking prices by hand.')
        ->assertSee(route('privacy'))
        ->assertSee('verification email');
});

test('the header offers account creation to guests and the app to members', function (): void {
    $this->get(route('home'))->assertSee('Create account');

    $this->actingAs(User::factory()->create());

    $this->get(route('home'))
        ->assertSee('Open app')
        ->assertDontSee('Stop checking prices by hand.');
});

test('the contact link only renders when a contact address is configured', function (): void {
    config()->set('site.contact_email', null);
    $this->get(route('home'))->assertDontSee('mailto:', escape: false);

    config()->set('site.contact_email', 'hello@example.test');
    $this->get(route('home'))->assertSee('mailto:hello@example.test', escape: false);
});

test('the privacy page renders for guests', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('What we store')
        ->assertSee('Resend')
        ->assertSee('hello@example.test');
});

test('robots.txt keeps crawlers out of the app but allows the public pages', function (): void {
    $robots = file_get_contents(public_path('robots.txt'));

    expect($robots)->toContain('Disallow: /app')
        ->toContain('Disallow: /admin')
        ->toContain('Allow: /');
});
