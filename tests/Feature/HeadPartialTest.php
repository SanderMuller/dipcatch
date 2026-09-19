<?php declare(strict_types=1);

use App\Models\User;

test('page titles carry no stray whitespace', function (string $url): void {
    $content = (string) $this->get($url)->assertOk()->getContent();

    $found = preg_match('#<title>(.*?)</title>#s', $content, $matches);

    expect($found)->toBe(1);

    $title = $matches[1] ?? '';

    expect($title)->not->toBeEmpty()->and($title)->toBe(trim($title));
})->with([
    'homepage' => '/',
    'pricing' => '/pricing',
    'privacy' => '/privacy',
    'use case' => '/price-alerts/groceries',
    'register' => '/register',
]);

test('every indexable marketing page ships a full social card', function (string $url): void {
    $this->get($url)->assertOk()->assertSeeHtml('<meta property="og:title"')->assertSeeHtml('<meta property="og:description"')->assertSeeHtml('<meta property="og:url"')->assertSeeHtml('<meta name="twitter:card" content="summary_large_image">')->assertSeeHtml('<meta name="twitter:title"')
        ->assertSee('og-default.png');
})->with(['homepage' => '/', 'pricing' => '/pricing', 'privacy' => '/privacy']);

test('the social image is the size the card specs ask for', function (): void {
    $path = public_path('images/og-default.png');

    expect(file_exists($path))->toBeTrue();

    $size = getimagesize($path);

    expect($size)->toBeArray()
        ->and($size[0] ?? null)->toBe(1200)
        ->and($size[1] ?? null)->toBe(630);
});

test('the social card declares the image dimensions so scrapers need not fetch it', function (): void {
    $this->get('/')->assertOk()->assertSeeHtml('<meta property="og:image:width" content="1200">')->assertSeeHtml('<meta property="og:image:height" content="630">');
});

test('pages describe themselves to search engines', function (string $url, string $needle): void {
    $this->get($url)->assertOk()->assertSeeHtml($needle);
})->with([
    'homepage' => ['/', '<meta name="description"'],
    'pricing' => ['/pricing', 'Pro removes the limits'],
    'privacy' => ['/privacy', 'What DipCatch stores about you'],
    'use case' => ['/price-alerts/groceries', 'Supermarket prices move every week'],
    'register' => ['/register', 'No card, no extension'],
]);

test('the registration page stays indexable because it is where visitors convert', function (): void {
    $content = (string) $this->get('/register')->assertOk()->getContent();

    expect($content)->not->toContain('name="robots"');
});

test('every other auth page tells search engines to stay out', function (string $url): void {
    $this->get($url)->assertOk()->assertSeeHtml('<meta name="robots" content="noindex">');
})->with([
    'login' => '/login',
    'forgot password' => '/forgot-password',
]);

test('auth pages other than register emit no half-built social card', function (): void {
    $content = (string) $this->get('/login')->assertOk()->getContent();

    expect($content)->not->toContain('property="og:');
});

test('the app shell emits no social card of its own', function (): void {
    $this->actingAs(User::factory()->create());

    // The Flux app shell. It lived at /dashboard until the starter placeholder
    // was removed; the shell itself is what this asserts about.
    $content = (string) $this->get(route('app.dashboard'))->assertOk()->getContent();

    expect($content)->not->toContain('property="og:');
});

test('the Dutch homepage names its own locale and the alternate', function (): void {
    $this->get(route('home', ['lang' => 'nl']))->assertOk()->assertSeeHtml('<meta property="og:locale" content="nl_NL">')->assertSeeHtml('<meta property="og:locale:alternate" content="en_US">');
});

test('the English homepage names its own locale and the alternate', function (): void {
    $this->get(route('home'))->assertOk()->assertSeeHtml('<meta property="og:locale" content="en_US">')->assertSeeHtml('<meta property="og:locale:alternate" content="nl_NL">');
});

test('the font stylesheet asks for a swap so text paints before the webfont lands', function (): void {
    $this->get('/')->assertOk()->assertSeeHtml('display=swap');
});

test('pages declare the light theme colour, because light is the default mode', function (): void {
    // One colour, not one per OS scheme: the appearance script defaults to
    // light whatever the OS says, so a dark colour keyed on the OS painted
    // the wrong browser chrome. The script rewrites it when dark is chosen.
    $this->get('/')->assertOk()->assertSeeHtml('<meta name="theme-color" content="#fffbeb">')->assertDontSeeHtml('media="(prefers-color-scheme');
});

test('nothing stored means light, not the operating system preference', function (): void {
    $this->get('/')->assertOk()->assertSeeHtml("window.localStorage.getItem('flux.appearance') || 'light'");
});

test('signing in changes the page body but not what crawlers read', function (): void {
    // Livewire injects its own styles into one of the two responses, so
    // compare the tags a crawler acts on rather than the whole head.
    $seoTags = static function (string $content): array {
        preg_match_all(
            '#<(?:title|meta|link)\b[^>]*>#i',
            $content,
            $matches,
        );

        return array_values(array_filter(
            $matches[0],
            static fn (string $tag): bool => (bool) preg_match('#og:|twitter:|canonical|description|robots|hreflang|<title#i', $tag),
        ));
    };

    $guest = $seoTags((string) $this->get('/')->assertOk()->getContent());

    $this->actingAs(User::factory()->create());

    $authed = $seoTags((string) $this->get('/')->assertOk()->getContent());

    expect($authed)->toBe($guest)->not->toBeEmpty();
});
