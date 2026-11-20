<?php declare(strict_types=1);

use App\Analytics\Contracts\ResolvesCountry;
use App\Analytics\Contracts\ResolvesSection;
use App\Analytics\Enums\DeviceClass;
use App\Analytics\Enums\PageViewSection;
use App\Analytics\Http\RecordPageView;
use App\Analytics\Models\PageView;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

function analyticsDesktopUa(): string
{
    return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
}

function analyticsPhoneUa(): string
{
    return 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function analyticsVisit(string $uri, array $headers = []): TestResponse
{
    return test()->withHeaders(['User-Agent' => analyticsDesktopUa(), ...$headers])->get($uri);
}

function analyticsOnlyPageView(): PageView
{
    expect(PageView::query()->count())->toBe(1);

    return PageView::query()->sole();
}

it('records a marketing page load by a guest', function (): void {
    analyticsVisit('/pricing?utm_source=newsletter&utm_medium=email&utm_campaign=black-friday&lang=nl', [
        'Referer' => 'https://www.Google.com/search?q=dipcatch',
        'CF-IPCountry' => 'be',
    ])->assertOk();

    $view = analyticsOnlyPageView();

    expect($view->section)->toBe(PageViewSection::Marketing)
        ->and($view->route_name)->toBe('pricing')
        ->and($view->path)->toBe('/pricing')
        ->and($view->referrer_host)->toBe('google.com')
        ->and($view->utm_source)->toBe('newsletter')
        ->and($view->utm_medium)->toBe('email')
        ->and($view->utm_campaign)->toBe('black-friday')
        ->and($view->country)->toBe('BE')
        ->and($view->device)->toBe(DeviceClass::Desktop)
        ->and($view->is_authenticated)->toBeFalse()
        ->and($view->user_id)->toBeNull()
        ->and($view->visitor_hash)->toMatch('/^[0-9a-f]{32}$/');
});

it('stores no IP address, query string or full referrer in any column', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
    analyticsVisit('/pricing?email=someone@example.test&idea=secret', [
        'Referer' => 'https://news.example.test/article/42?ref=abc',
    ])->assertOk();

    $stored = implode('|', array_map(strval(...), array_filter((array) PageView::query()->toBase()->sole(), is_scalar(...))));

    expect($stored)->not->toContain('203.0.113.7')
        ->and($stored)->not->toContain('someone@example.test')
        ->and($stored)->not->toContain('secret')
        ->and($stored)->not->toContain('article/42')
        ->and(analyticsOnlyPageView()->referrer_host)->toBe('news.example.test');
});

it('links a signed-in user who did not opt out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);
    analyticsVisit('/app/stats')->assertOk();

    $view = analyticsOnlyPageView();

    expect($view->section)->toBe(PageViewSection::App)
        ->and($view->route_name)->toBe('app.stats')
        ->and($view->path)->toBe('/app/stats')
        ->and($view->is_authenticated)->toBeTrue()
        ->and($view->user_id)->toBe($user->id);
});

it('does not link a signed-in user who opted out', function (): void {
    $user = User::factory()->create(['usage_tracking_opted_out' => true]);

    $this->actingAs($user);
    analyticsVisit('/app/stats')->assertOk();

    expect(analyticsOnlyPageView())
        ->user_id->toBeNull()
        ->is_authenticated->toBeTrue();
});

it('counts a settings page as app', function (): void {
    $this->actingAs(User::factory()->create());
    analyticsVisit('/settings/appearance')->assertOk();

    expect(analyticsOnlyPageView()->section)->toBe(PageViewSection::App);
});

it('counts a wire:navigate page load', function (): void {
    analyticsVisit('/pricing', ['X-Livewire-Navigate' => '1'])->assertOk();

    expect(PageView::query()->count())->toBe(1);
});

it('skips a browser prefetch', function (string $header, string $value): void {
    analyticsVisit('/pricing', [$header => $value])->assertOk();

    expect(PageView::query()->count())->toBe(0);
})->with([
    'Sec-Purpose' => ['Sec-Purpose', 'prefetch;prerender'],
    'Purpose' => ['Purpose', 'prefetch'],
]);

it('skips bots and requests without a user agent', function (string $userAgent): void {
    $this->withHeaders(['User-Agent' => $userAgent])->get('/pricing')->assertOk();

    expect(PageView::query()->count())->toBe(0);
})->with([
    'Googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'curl' => 'curl/8.7.1',
    'empty' => '',
]);

it('skips redirects, errors and non-HTML responses', function (string $uri, int $status): void {
    analyticsVisit($uri)->assertStatus($status);

    expect(PageView::query()->count())->toBe(0);
})->with([
    'redirect' => ['/privacy-policy', 301],
    'not found' => ['/this-page-does-not-exist', 404],
    'xml' => ['/sitemap.xml', 200],
]);

it('skips a guest redirected away from the app', function (): void {
    analyticsVisit('/app')->assertRedirect();

    expect(PageView::query()->count())->toBe(0);
});

it('skips a server error', function (): void {
    Route::middleware('web')->get('/analytics-test/fails', fn () => abort(500));

    analyticsVisit('/analytics-test/fails')->assertStatus(500);

    expect(PageView::query()->count())->toBe(0);
});

it('skips excluded routes', function (): void {
    analyticsVisit('/bot')->assertOk();

    expect(PageView::query()->count())->toBe(0);
});

it('drops a referrer from the app itself', function (): void {
    analyticsVisit('/pricing', ['Referer' => rtrim(config()->string('app.url'), '/') . '/shops'])->assertOk();

    expect(analyticsOnlyPageView()->referrer_host)->toBeNull();
});

it('stores the URI template for a route that is not public', function (): void {
    Route::middleware('web')->get('/analytics-test/reset/{token}', fn () => response('<p>ok</p>'))->name('analytics-test.reset');

    analyticsVisit('/analytics-test/reset/s3cr3t-t0ken')->assertOk();

    expect(analyticsOnlyPageView()->path)->toBe('/analytics-test/reset/{token}')
        ->and(analyticsOnlyPageView()->path)->not->toContain('s3cr3t');
});

it('truncates long values to the column length', function (): void {
    analyticsVisit('/pricing?utm_campaign=' . str_repeat('a', 300))->assertOk();

    expect(mb_strlen((string) analyticsOnlyPageView()->utm_campaign))->toBe(100);
});

it('leaves the country empty when the header is missing or unknown', function (?string $header): void {
    analyticsVisit('/pricing', $header === null ? [] : ['CF-IPCountry' => $header])->assertOk();

    expect(analyticsOnlyPageView()->country)->toBeNull();
})->with([
    'missing' => [null],
    'unknown' => ['XX'],
    'tor' => ['T1'],
    'garbage' => ['N1L'],
]);

it('classifies a phone as mobile', function (): void {
    analyticsVisit('/pricing', ['User-Agent' => analyticsPhoneUa()])->assertOk();

    expect(analyticsOnlyPageView()->device)->toBe(DeviceClass::Mobile);
});

it('gives the same visitor the same hash on one day and a new hash the next day', function (): void {
    $this->travelTo(now()->setTime(10, 0));
    analyticsVisit('/pricing')->assertOk();
    analyticsVisit('/support')->assertOk();

    $this->travelTo(now()->addDay());
    analyticsVisit('/pricing')->assertOk();

    $hashes = PageView::query()->orderBy(PageView::ID)->pluck(PageView::VISITOR_HASH)->all();

    expect($hashes[0])->toBe($hashes[1])
        ->and($hashes[2])->not->toBe($hashes[0]);
});

it('keeps the page working when collecting the values fails', function (): void {
    config(['analytics.country_resolver' => stdClass::class]);
    Exceptions::fake();

    analyticsVisit('/pricing')->assertOk();

    Exceptions::assertReported(UnexpectedValueException::class);

    expect(PageView::query()->count())->toBe(0);
});

it('reports and swallows a failed write after the response', function (): void {
    $request = Request::create('/pricing');
    $request->attributes->set(RecordPageView::ATTRIBUTE, ['no_such_column' => 'x']);

    Exceptions::fake();

    new RecordPageView()->terminate($request, response('ok'));

    Exceptions::assertReported(QueryException::class);
});

it('records nothing when analytics is switched off', function (): void {
    config(['analytics.enabled' => false]);

    analyticsVisit('/pricing')->assertOk();

    expect(PageView::query()->count())->toBe(0);
});

it('uses the section and country resolvers from the config', function (): void {
    app()->bind('test.section', fn (): ResolvesSection => new class implements ResolvesSection {
        public function section(Request $request): PageViewSection
        {
            return PageViewSection::App;
        }
    });
    app()->bind('test.country', fn (): ResolvesCountry => new class implements ResolvesCountry {
        public function country(Request $request): string
        {
            return 'DE';
        }
    });
    config(['analytics.section_resolver' => 'test.section', 'analytics.country_resolver' => 'test.country']);

    analyticsVisit('/pricing')->assertOk();

    expect(analyticsOnlyPageView())
        ->section->toBe(PageViewSection::App)
        ->country->toBe('DE');
});

it('deletes linked page views with the account', function (): void {
    $user = User::factory()->create();
    PageView::factory()->linked($user)->create();
    PageView::factory()->anonymous()->create();

    $user->delete();

    expect(PageView::query()->count())->toBe(1)
        ->and(PageView::query()->sole()->user_id)->toBeNull();
});

it('drops a UTM value that is not a plain string', function (): void {
    analyticsVisit('/pricing?utm_source[]=a&utm_campaign=spring')->assertOk();

    expect(analyticsOnlyPageView())
        ->utm_source->toBeNull()
        ->utm_campaign->toBe('spring');
});

it('deletes older daily salts when a new one is made', function (): void {
    Cache::forever('analytics:salt:2026-11-18', 'old');
    Cache::forever('analytics:salt:2026-08-23', 'after a long gap');
    Cache::forever('analytics:salt:2026-11-19', 'yesterday');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00'));

    analyticsVisit('/pricing')->assertOk();

    expect(Cache::has('analytics:salt:2026-11-18'))->toBeFalse()
        ->and(Cache::has('analytics:salt:2026-08-23'))->toBeFalse()
        ->and(Cache::get('analytics:salt:2026-11-19'))->toBe('yesterday')
        ->and(Cache::has('analytics:salt:2026-11-20'))->toBeTrue();
});
