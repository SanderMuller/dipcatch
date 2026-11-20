<?php declare(strict_types=1);

use App\Analytics\Models\PageView;
use App\Livewire\Settings\UsageTracking;
use App\Models\User;
use Laravel\Fortify\Features;

use function Pest\Livewire\livewire;

function analyticsSignupUa(): string
{
    return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
}

/**
 * @return array<string, string>
 */
function analyticsRegistration(string $email = 'new@example.test'): array
{
    return [
        'name' => 'New Person',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
}

it('keeps the first external source a guest arrives with', function (): void {
    $this->withHeaders(['User-Agent' => analyticsSignupUa(), 'Referer' => 'https://news.example.test/a'])
        ->get('/pricing?utm_source=newsletter&utm_campaign=autumn')
        ->assertOk()
        ->assertSessionHas('signup_source', [
            'referrer_host' => 'news.example.test',
            'utm_source' => 'newsletter',
            'utm_medium' => null,
            'utm_campaign' => 'autumn',
        ]);

    $this->withHeaders(['User-Agent' => analyticsSignupUa(), 'Referer' => 'https://other.example.test/'])
        ->get('/support?utm_source=ads')
        ->assertOk()
        ->assertSessionHas('signup_source.utm_source', 'newsletter')
        ->assertSessionHas('signup_source.referrer_host', 'news.example.test');
});

it('keeps no source for a guest without a referrer or campaign', function (): void {
    $this->withHeaders(['User-Agent' => analyticsSignupUa()])
        ->get('/pricing')
        ->assertOk()
        ->assertSessionMissing('signup_source');
});

it('keeps no source from a request that is not counted', function (array $headers, string $uri): void {
    $this->withHeaders($headers)->get($uri)->assertSessionMissing('signup_source');
})->with([
    'bot' => [['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'], '/pricing?utm_source=x'],
    'prefetch' => [['User-Agent' => analyticsSignupUa(), 'Sec-Purpose' => 'prefetch'], '/pricing?utm_source=x'],
    'redirect' => [['User-Agent' => analyticsSignupUa()], '/privacy-policy?utm_source=x'],
]);

it('keeps no source for a signed-in user', function (): void {
    $this->actingAs(User::factory()->create())
        ->withHeaders(['User-Agent' => analyticsSignupUa()])
        ->get('/pricing?utm_source=newsletter')
        ->assertSessionMissing('signup_source');
});

it('stores the source on the account at registration', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->withSession(['signup_source' => [
        'referrer_host' => 'news.example.test',
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'autumn',
    ]])->post(route('register.store'), analyticsRegistration())
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('signup_source');

    $user = User::query()->where('email', 'new@example.test')->sole();

    expect($user->signup_referrer_host)->toBe('news.example.test')
        ->and($user->signup_utm_source)->toBe('newsletter')
        ->and($user->signup_utm_medium)->toBe('email')
        ->and($user->signup_utm_campaign)->toBe('autumn');
});

it('leaves the source empty when a guest registers without one', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->post(route('register.store'), analyticsRegistration())->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'new@example.test')->sole();

    expect($user->signup_referrer_host)->toBeNull()
        ->and($user->signup_utm_source)->toBeNull()
        ->and($user->signup_utm_medium)->toBeNull()
        ->and($user->signup_utm_campaign)->toBeNull();
});

it('shows the switch on, and saving it off unlinks past views', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    PageView::factory()->linked($user)->count(2)->create();
    PageView::factory()->linked($other)->create();

    $this->actingAs($user);

    livewire(UsageTracking::class)
        ->assertSet('usage_tracking', true)
        ->set('usage_tracking', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->usage_tracking_opted_out)->toBeTrue()
        ->and($user->allowsUsageTracking())->toBeFalse()
        ->and(PageView::query()->where(PageView::USER_ID, $user->id)->count())->toBe(0)
        ->and(PageView::query()->whereNull(PageView::USER_ID)->count())->toBe(2)
        ->and(PageView::query()->where(PageView::USER_ID, $other->id)->count())->toBe(1);
});

it('links only new views after switching back on', function (): void {
    $user = User::factory()->create(['usage_tracking_opted_out' => true]);
    PageView::factory()->create([PageView::USER_ID => null, PageView::IS_AUTHENTICATED => true]);

    $this->actingAs($user);

    livewire(UsageTracking::class)
        ->assertSet('usage_tracking', false)
        ->set('usage_tracking', true)
        ->call('save');

    expect($user->refresh()->allowsUsageTracking())->toBeTrue()
        ->and(PageView::query()->whereNotNull(PageView::USER_ID)->count())->toBe(0);

    $this->withHeaders(['User-Agent' => analyticsSignupUa()])->get('/app/stats')->assertOk();

    expect(PageView::query()->where(PageView::USER_ID, $user->id)->count())->toBe(1);
});

it('shows the switch on the profile page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Use my visits to improve DipCatch');
});

it('explains visit counting on the privacy page', function (): void {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('we count the pages people open on this site ourselves, without cookies or analytics scripts.', false)
        ->assertSee('Page visits are kept for 32 days', false);
});
