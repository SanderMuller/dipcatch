<?php declare(strict_types=1);

use App\Analytics\Enums\StatPeriod;
use App\Analytics\Models\PageView;
use App\Analytics\Models\PageViewPeriodStat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\artisan;

/**
 * @param  array<array-key, mixed>  $cookies
 * @param  array<array-key, mixed>  $headers
 * @return TestResponse<Response>
 */
function visitorCookieVisit(string $country = 'NL', array $cookies = [], array $headers = [], string $uri = '/pricing'): TestResponse
{
    $test = test()->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
        'CF-IPCountry' => $country,
        ...$headers,
    ]);

    foreach ($cookies as $name => $value) {
        $test->withCookie((string) $name, is_string($value) ? $value : '');
    }

    return $test->get($uri);
}

function visitorCookieHmac(string $value): string
{
    return substr(hash_hmac('sha256', $value, config()->string('app.key')), 0, 32);
}

/**
 * @param  TestResponse<Response>  $response
 */
function visitorCookieSet(TestResponse $response): ?Cookie
{
    return array_find($response->headers->getCookies(), static fn (Cookie $cookie): bool => $cookie->getName() === 'dipcatch_vid');
}

beforeEach(function (): void {
    config()->set('analytics.visitor_cookie.enabled', true);
});

it('gives a guest in a listed country a visitor cookie and stores only its keyed hash', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00'));

    $response = visitorCookieVisit('NL')->assertOk();

    $cookie = $response->getCookie('dipcatch_vid');
    $value = (string) $cookie?->getValue();
    $view = PageView::query()->sole();

    expect($value)->toMatch('/^[a-f0-9]{64}$/')
        ->and($view->visitor_id)->toBe(visitorCookieHmac($value))
        ->and($view->visitor_id)->not->toBe($value)
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe(Cookie::SAMESITE_LAX)
        ->and($cookie?->getPath())->toBe('/')
        ->and($cookie?->getExpiresTime())->toBe(CarbonImmutable::parse('2026-11-20 12:00:00')->addDays(395)->getTimestamp());
});

it('reads the cookie on a later visit without setting it again', function (): void {
    $value = str_repeat('ab', 32);

    $response = visitorCookieVisit('FR', ['dipcatch_vid' => $value])->assertOk();

    expect(visitorCookieSet($response))->toBeNull()
        ->and(PageView::query()->sole()->visitor_id)->toBe(visitorCookieHmac($value));
});

it('replaces a cookie that is not a valid visitor id', function (string $value): void {
    $response = visitorCookieVisit('NL', ['dipcatch_vid' => $value])->assertOk();

    $newValue = (string) $response->getCookie('dipcatch_vid')?->getValue();

    expect($newValue)->toMatch('/^[a-f0-9]{64}$/')->not->toBe($value)
        ->and(PageView::query()->sole()->visitor_id)->toBe(visitorCookieHmac($newValue));
})->with(['too short' => 'abc123', 'not hex' => str_repeat('zz', 32)]);

it('neither sets nor reads the cookie outside the listed countries', function (string $country, array $cookies): void {
    $response = visitorCookieVisit($country, $cookies)->assertOk();

    expect(visitorCookieSet($response))->toBeNull()
        ->and(PageView::query()->sole()->visitor_id)->toBeNull();
})->with([
    'Germany' => ['DE', []],
    'unknown country' => ['XX', []],
    'Tor' => ['T1', []],
    'a Dutch visitor cookie, seen from Germany' => ['DE', ['dipcatch_vid' => str_repeat('ab', 32)]],
]);

it('neither sets nor reads the cookie for a signed-in user', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = visitorCookieVisit('NL', ['dipcatch_vid' => str_repeat('ab', 32)], uri: '/app')->assertOk();

    expect(visitorCookieSet($response))->toBeNull()
        ->and(PageView::query()->sole()->visitor_id)->toBeNull()
        ->and(PageView::query()->sole()->user_id)->toBe($user->id);
});

it('neither sets nor reads the cookie while the switch is off', function (): void {
    config()->set('analytics.visitor_cookie.enabled', false);

    $response = visitorCookieVisit('NL', ['dipcatch_vid' => str_repeat('ab', 32)])->assertOk();

    expect(visitorCookieSet($response))->toBeNull()
        ->and(PageView::query()->sole()->visitor_id)->toBeNull();
});

it('sets no cookie for a request that is not counted', function (): void {
    $response = visitorCookieVisit('NL', headers: ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])->assertOk();

    expect(visitorCookieSet($response))->toBeNull()
        ->and(PageView::query()->count())->toBe(0);
});

it('counts nothing for a browser that objected, also with the switch off', function (bool $switch, array $cookies, array $headers): void {
    config()->set('analytics.visitor_cookie.enabled', $switch);

    $response = visitorCookieVisit('NL', $cookies, $headers, '/pricing?utm_source=newsletter')->assertOk();

    expect(visitorCookieSet($response))->toBeNull()
        ->and(PageView::query()->count())->toBe(0)
        ->and(session()->has('signup_source'))->toBeFalse();
})->with([
    'objection cookie' => [true, ['dipcatch_no_count' => '1'], []],
    'objection cookie, switch off' => [false, ['dipcatch_no_count' => '1'], []],
    'Global Privacy Control' => [true, [], ['Sec-GPC' => '1']],
    'Global Privacy Control, switch off' => [false, [], ['Sec-GPC' => '1']],
]);

it('counts nothing for a signed-in user who objected', function (): void {
    $this->actingAs(User::factory()->create());

    visitorCookieVisit('NL', headers: ['Sec-GPC' => '1'], uri: '/app')->assertOk();

    expect(PageView::query()->count())->toBe(0);
});

it('stores an objection, forgets the visitor cookie and the signup source', function (): void {
    $response = $this->withSession(['signup_source' => ['utm_source' => 'newsletter']])
        ->withCookie('dipcatch_vid', str_repeat('ab', 32))
        ->post(route('analytics.objection.store', ['lang' => 'nl']));

    $response->assertRedirect('/privacy?lang=nl#count-visits')
        ->assertCookie('dipcatch_no_count')
        ->assertCookieExpired('dipcatch_vid')
        ->assertSessionMissing('signup_source');

    expect($response->getCookie('dipcatch_no_count')?->getExpiresTime())->toBeGreaterThan(now()->addDays(394)->getTimestamp());
});

it('deletes the cookies on the scope they were set with, whatever the session cookie scope', function (): void {
    config()->set('session.domain', '.dipcatch.test');
    config()->set('session.path', '/app');

    $stored = $this->post(route('analytics.objection.store'));
    $taken = $this->delete(route('analytics.objection.destroy'));

    foreach ([$stored->getCookie('dipcatch_vid', false), $stored->getCookie('dipcatch_no_count', false), $taken->getCookie('dipcatch_no_count', false)] as $cookie) {
        expect($cookie?->getDomain())->toBeNull()
            ->and($cookie?->getPath())->toBe('/');
    }
});

it('counts the browser again after the objection is taken back, with a new visitor id', function (): void {
    $old = str_repeat('ab', 32);

    $this->withCookie('dipcatch_no_count', '1')
        ->delete(route('analytics.objection.destroy'))
        ->assertRedirect('/privacy#count-visits')
        ->assertCookieExpired('dipcatch_no_count');

    $this->flushHeaders();
    $this->defaultCookies = [];

    $value = (string) visitorCookieVisit('NL')->getCookie('dipcatch_vid')?->getValue();

    expect($value)->toMatch('/^[a-f0-9]{64}$/')->not->toBe($old)
        ->and(PageView::query()->sole()->visitor_id)->toBe(visitorCookieHmac($value));
});

it('counts one cookie visitor across several days once per week and month', function (): void {
    $visitorId = visitorCookieHmac(str_repeat('ab', 32));
    $user = User::factory()->create();

    foreach (['2026-11-16', '2026-11-17', '2026-11-18'] as $day) {
        PageView::factory()->anonymous()->create([PageView::VIEWED_AT => CarbonImmutable::parse("{$day} 10:00"), PageView::VISITOR_ID => $visitorId]);
    }

    PageView::factory()->linked($user)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-17 10:00')]);
    PageView::factory()->anonymous()->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-17 11:00')]);

    $this->travelTo(CarbonImmutable::parse('2026-11-20 00:20:00'));
    artisan('analytics:rollup')->assertSuccessful();

    $week = PageViewPeriodStat::query()
        ->where(PageViewPeriodStat::PERIOD, StatPeriod::Week->value)
        ->where(PageViewPeriodStat::SECTION, PageViewPeriodStat::ALL_SECTIONS)
        ->sole();

    expect($week->visitors)->toBe(2)
        ->and($week->users)->toBe(1)
        ->and(PageViewPeriodStat::query()->where(PageViewPeriodStat::SECTION, 'marketing')->where(PageViewPeriodStat::PERIOD, StatPeriod::Week->value)->sole()->users)->toBe(0);
});

it('describes the visitor cookie on the privacy page only while the switch is on', function (bool $switch): void {
    config()->set('analytics.visitor_cookie.enabled', $switch);

    $response = $this->get(route('privacy'))->assertOk();

    if ($switch) {
        $response->assertSee('Visitors from the Netherlands and France also get a cookie with a random number')
            ->assertSee('we do not use advertising cookies or third-party analytics scripts')
            ->assertDontSee('without cookies or analytics scripts');
    } else {
        $response->assertSee('without cookies or analytics scripts')
            ->assertDontSee('Visitors from the Netherlands and France');
    }

    $response->assertSee('A Global Privacy Control signal from your browser has the same effect.');
})->with(['switch on' => true, 'switch off' => false]);

it('shows the objection button that fits the browser', function (): void {
    $this->get(route('privacy'))
        ->assertSee('Don’t count my visits')
        ->assertDontSee('Count my visits again');

    $this->withCookie('dipcatch_no_count', '1')
        ->get(route('privacy'))
        ->assertSee('Count my visits again')
        ->assertDontSee('Don’t count my visits');

    $this->defaultCookies = [];

    $this->withHeader('Sec-GPC', '1')
        ->get(route('privacy'))
        ->assertSee('Your browser sends this signal, so your visits are not counted.')
        ->assertSee('Don’t count my visits');
});

it('renders the Dutch privacy page with the switch on without an untranslated string', function (): void {
    $missing = [];
    Lang::handleMissingKeysUsing(function (string $key) use (&$missing): void {
        $missing[$key] = true;
    });

    try {
        $this->get(route('privacy', ['lang' => 'nl']))->assertOk();
        $this->withCookie('dipcatch_no_count', '1')->get(route('privacy', ['lang' => 'nl']))->assertOk();
        $this->withHeader('Sec-GPC', '1')->get(route('privacy', ['lang' => 'nl']))->assertOk();
    } finally {
        Lang::handleMissingKeysUsing(null);
    }

    expect(array_keys($missing))->toBe([]);
});
