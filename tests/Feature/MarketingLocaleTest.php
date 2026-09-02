<?php declare(strict_types=1);

use App\Http\Middleware\MarketingLocale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

test('the bare marketing URL renders English whatever Accept-Language says', function (): void {
    $withoutHeader = $this->get(route('home'))->assertOk();
    $dutchHeader = $this->withHeaders(['Accept-Language' => 'nl-NL,nl;q=0.9'])->get(route('home'))->assertOk();
    $germanHeader = $this->withHeaders(['Accept-Language' => 'de-DE,de;q=0.9'])->get(route('home'))->assertOk();

    foreach ([$withoutHeader, $dutchHeader, $germanHeader] as $response) {
        $response->assertSee('<html lang="en"', escape: false)
            ->assertDontSee('<html lang="nl"', escape: false)
            ->assertSee('Same product, every supermarket, one alert.')
            ->assertSee('<link rel="canonical" href="' . route('home') . '">', escape: false)
            ->assertSee('<meta property="og:locale" content="en_US">', escape: false)
            ->assertDontSee('Zelfde product', escape: false);
    }
});

test('the bare privacy URL renders English whatever Accept-Language says', function (): void {
    $this->withHeaders(['Accept-Language' => 'nl-NL,nl;q=0.9'])
        ->get(route('privacy'))
        ->assertOk()
        ->assertSee('<html lang="en"', escape: false)
        ->assertSee('What we store');
});

test('?lang=nl renders Dutch and ?lang=en renders English', function (): void {
    $this->get(route('home', ['lang' => 'nl']))
        ->assertOk()
        ->assertSee('<html lang="nl"', escape: false)
        ->assertSee('Zelfde product, elke supermarkt, één melding.', escape: false);

    $this->get(route('home', ['lang' => 'en']))
        ->assertOk()
        ->assertSee('<html lang="en"', escape: false)
        ->assertSee('Same product, every supermarket, one alert.');

    $this->get(route('privacy', ['lang' => 'nl']))
        ->assertOk()
        ->assertSee('<html lang="nl"', escape: false)
        ->assertSee('Wat we opslaan');
});

test('a malformed ?lang value renders English and never reaches the page', function (string $value, array $forbidden): void {
    $response = $this->get('/?lang=' . urlencode($value))->assertOk();

    $response->assertSee('<html lang="en"', escape: false)
        ->assertSee('Same product, every supermarket, one alert.');

    $content = (string) $response->getContent();

    foreach ($forbidden as $needle) {
        expect($content)->not->toContain($needle);
    }
})->with([
    'unsupported language' => ['fr', ['lang=fr', 'lang="fr"', 'hreflang="fr"']],
    'script injection' => [
        '<script>alert(1)</script>',
        ['alert(1)', 'alert%281%29', '%3Cscript%3E', 'lang=<script>'],
    ],
]);

test('the Accept-Language header cannot override a malformed ?lang value', function (): void {
    $this->withHeaders(['Accept-Language' => 'nl-NL,nl;q=0.9'])
        ->get('/?lang=fr')
        ->assertOk()
        ->assertSee('<html lang="en"', escape: false);
});

test('canonical, hreflang and og:locale describe two reciprocal representations', function (string $route, ?string $lang, ?string $canonicalLang, string $ogLocale): void {
    $bare = route($route);
    $dutch = route($route, ['lang' => 'nl']);
    $canonical = $canonicalLang === null ? $bare : route($route, ['lang' => $canonicalLang]);

    $response = $this->get($lang === null ? $bare : route($route, ['lang' => $lang]))->assertOk();

    $response->assertSee('<link rel="canonical" href="' . $canonical . '">', escape: false)
        ->assertSee('<link rel="alternate" hreflang="en" href="' . $bare . '">', escape: false)
        ->assertSee('<link rel="alternate" hreflang="nl" href="' . $dutch . '">', escape: false)
        ->assertSee('<link rel="alternate" hreflang="x-default" href="' . $bare . '">', escape: false)
        ->assertSee('<meta property="og:locale" content="' . $ogLocale . '">', escape: false);
})->with([
    'homepage, bare URL' => ['home', null, null, 'en_US'],
    'homepage, ?lang=nl' => ['home', 'nl', 'nl', 'nl_NL'],
    'homepage, ?lang=en canonicalises to the bare URL' => ['home', 'en', null, 'en_US'],
    'privacy, bare URL' => ['privacy', null, null, 'en_US'],
    'privacy, ?lang=nl' => ['privacy', 'nl', 'nl', 'nl_NL'],
    'privacy, ?lang=en canonicalises to the bare URL' => ['privacy', 'en', null, 'en_US'],
]);

test('both marketing pages carry a language toggle linking to their own two representations', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('href="' . route('home', ['lang' => 'nl']) . '" hreflang="nl"', escape: false)
        ->assertSee('href="' . route('home', ['lang' => 'en']) . '" hreflang="en"', escape: false);

    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('href="' . route('privacy', ['lang' => 'nl']) . '" hreflang="nl"', escape: false)
        ->assertSee('href="' . route('privacy', ['lang' => 'en']) . '" hreflang="en"', escape: false);
});

test('links between the marketing pages carry the current ?lang value', function (): void {
    $this->get(route('home', ['lang' => 'nl']))
        ->assertOk()
        ->assertSee('href="' . route('privacy', ['lang' => 'nl']) . '"', escape: false);

    $this->get(route('privacy', ['lang' => 'nl']))
        ->assertOk()
        ->assertSee('href="' . route('home', ['lang' => 'nl']) . '"', escape: false);
});

test('the bare URLs link to each other without a ?lang value', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('href="' . route('privacy') . '"', escape: false);

    $this->get('/?lang=fr')
        ->assertOk()
        ->assertSee('href="' . route('privacy') . '"', escape: false);
});

test('the marketing routes stay out of shared caches', function (string $path): void {
    $response = $this->get($path)->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->toContain('private');
})->with([
    'homepage' => ['/'],
    'privacy' => ['/privacy'],
    'Dutch homepage' => ['/?lang=nl'],
    'Dutch privacy' => ['/privacy?lang=nl'],
]);

test('a Dutch marketing request does not leak its locale into the next request', function (): void {
    // Octane keeps the container between requests, so the middleware must put
    // the locale back. Both requests run in this one process.
    $this->get(route('home', ['lang' => 'nl']))
        ->assertOk()
        ->assertSee('<html lang="nl"', escape: false);

    expect(App::getLocale())->toBe('en');

    $this->actingAs(User::factory()->create());

    $this->get('/app')->assertSuccessful();

    expect(App::getLocale())->toBe('en');
});

test('the middleware restores the locale when the request throws', function (): void {
    App::setLocale('en');

    $middleware = new MarketingLocale();
    $request = Request::create('/?lang=nl');

    $seen = [];

    $throwing = function () use (&$seen): never {
        $seen[] = App::getLocale();

        throw new RuntimeException('boom');
    };

    expect(function () use ($middleware, $request, $throwing): void {
        $middleware->handle($request, $throwing);
    })->toThrow(RuntimeException::class, 'boom');

    expect($seen)->toBe(['nl'])
        ->and(App::getLocale())->toBe('en');
});

test('the middleware only accepts a published locale', function (): void {
    expect(MarketingLocale::requested(Request::create('/?lang=nl')))->toBe('nl')
        ->and(MarketingLocale::requested(Request::create('/?lang=en')))->toBe('en')
        ->and(MarketingLocale::requested(Request::create('/?lang=fr')))->toBeNull()
        ->and(MarketingLocale::requested(Request::create('/')))->toBeNull()
        ->and(MarketingLocale::requested(Request::create('/?lang[]=nl')))->toBeNull();
});
