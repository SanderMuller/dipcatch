<?php declare(strict_types=1);

use App\Models\User;
use App\Models\WaitlistSignup;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    RateLimiter::clear('waitlist:127.0.0.1');
});

test('guests see the homepage with the register CTA', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Same product, every supermarket, one alert.', escape: false);
    $response->assertSee('Create a free account');
    $response->assertSee(route('register'));
    $response->assertSee(route('login'));
});

test('authenticated users see the dashboard CTA instead of the register CTA', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Open dashboard');
    $response->assertDontSee('Create a free account');
});

test('a guest can join the wait list', function (): void {
    Livewire::test('waitlist-signup')
        ->set('email', 'Pat@Example.com')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    expect(WaitlistSignup::query()->where('email', 'pat@example.com')->exists())->toBeTrue();
});

test('the wait list rejects invalid emails', function (): void {
    Livewire::test('waitlist-signup')
        ->set('email', 'not-an-email')
        ->call('submit')
        ->assertHasErrors(['email' => 'email']);

    expect(WaitlistSignup::query()->count())->toBe(0);
});

test('joining twice with the same email is idempotent', function (): void {
    Livewire::test('waitlist-signup')->set('email', 'pat@example.com')->call('submit');
    Livewire::test('waitlist-signup')->set('email', 'pat@example.com')->call('submit');

    expect(WaitlistSignup::query()->where('email', 'pat@example.com')->count())->toBe(1);
});

test('the wait list is rate-limited per IP', function (): void {
    foreach (range(1, 5) as $i) {
        Livewire::test('waitlist-signup')
            ->set('email', "user{$i}@example.com")
            ->call('submit')
            ->assertHasNoErrors();
    }

    Livewire::test('waitlist-signup')
        ->set('email', 'user6@example.com')
        ->call('submit')
        ->assertHasErrors(['email']);
});

test('rejects empty email with the required rule', function (): void {
    Livewire::test('waitlist-signup')
        ->set('email', '')
        ->call('submit')
        ->assertHasErrors(['email' => 'required']);

    expect(WaitlistSignup::query()->count())->toBe(0);
});

test('rejects emails over 255 characters', function (): void {
    // 251 a's + '@x.co' (5 chars) = 256 chars total — one past the cap.
    $tooLong = str_repeat('a', 251) . '@x.co';

    Livewire::test('waitlist-signup')
        ->set('email', $tooLong)
        ->call('submit')
        ->assertHasErrors(['email' => 'max']);

    expect(WaitlistSignup::query()->count())->toBe(0);
});

test('persists the requesting IP and a length-capped user-agent', function (): void {
    Livewire::test('waitlist-signup')
        ->set('email', 'who@example.com')
        ->call('submit')
        ->assertHasNoErrors();

    /** @var WaitlistSignup $row */
    $row = WaitlistSignup::query()->where('email', 'who@example.com')->sole();
    expect($row->ip_address)->toBe('127.0.0.1')
        ->and(mb_strlen((string) ($row->user_agent ?? '')))->toBeLessThanOrEqual(255);
});

test('a rate-limited attempt does not consume a slot', function (): void {
    foreach (range(1, 5) as $i) {
        Livewire::test('waitlist-signup')
            ->set('email', "user{$i}@example.com")
            ->call('submit')
            ->assertHasNoErrors();
    }

    expect(RateLimiter::attempts('waitlist:127.0.0.1'))->toBe(5);

    // Sixth attempt should error WITHOUT incrementing — otherwise an attacker
    // could keep extending the lockout window indefinitely.
    Livewire::test('waitlist-signup')
        ->set('email', 'late@example.com')
        ->call('submit')
        ->assertHasErrors(['email']);

    expect(RateLimiter::attempts('waitlist:127.0.0.1'))->toBe(5);
});

test('after a successful submit the email field is reset', function (): void {
    Livewire::test('waitlist-signup')
        ->set('email', 'pat@example.com')
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertSet('email', '');
});

test('honeypot fill silently succeeds without writing a row or hitting the rate limit', function (): void {
    Livewire::test('waitlist-signup')
        ->set('email', 'spam@bot.test')
        ->set('company', 'BotCorp Inc.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true)
        ->assertSet('email', '')
        ->assertSet('company', '');

    expect(WaitlistSignup::query()->count())->toBe(0)
        ->and(RateLimiter::attempts('waitlist:127.0.0.1'))->toBe(0);
});

test('honeypot field is rendered off-screen and aria-hidden', function (): void {
    // The homepage no longer embeds the waitlist form (open registration),
    // so render the component directly.
    Livewire::test('waitlist-signup')
        ->assertSeeHtml('-left-[9999px]')
        ->assertSeeHtml('aria-hidden="true"')
        ->assertSeeHtml('id="waitlist-company"');
});

test('responses carry the Strict-Transport-Security header', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});
