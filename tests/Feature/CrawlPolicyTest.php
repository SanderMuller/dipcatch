<?php declare(strict_types=1);

use App\Support\MarketingPages;
use App\Support\UseCases;
use Illuminate\Support\Facades\Config;

/**
 * robots.txt lives in `public/` and is served by the web server, never by the
 * HTTP kernel, so these assertions read the file rather than requesting it.
 * The sitemap is a route and is driven over HTTP.
 */
function robotsGroup(string $userAgent): string
{
    $robots = (string) file_get_contents(public_path('robots.txt'));

    $blocks = preg_split('/\n\s*\n/', trim($robots)) ?: [];

    foreach ($blocks as $block) {
        if (str_contains($block, 'User-agent: ' . $userAgent . "\n")) {
            return $block;
        }
    }

    return '';
}

test('robots.txt lets every crawler reach the registration page', function (): void {
    expect(robotsGroup('*'))
        ->not->toBe('')
        ->not->toContain('Disallow: /register');
});

test('robots.txt keeps crawlers out of the app, admin and auth flows', function (string $path): void {
    expect(robotsGroup('*'))->toContain('Disallow: ' . $path);
})->with(['/app', '/admin', '/login', '/forgot-password', '/reset-password', '/two-factor-challenge', '/invite/', '/user/', '/stripe/', '/settings', '/dashboard']);

test('robots.txt leaves share pages crawlable so their noindex tag can be read', function (): void {
    // A disallowed page is never fetched, so Google never sees the
    // `noindex` on it and can still index the bare URL from a shared link.
    expect(robotsGroup('*'))->not->toContain('Disallow: /p/');
});

test('robots.txt names every AI crawler the site has a policy for', function (string $agent): void {
    $robots = (string) file_get_contents(public_path('robots.txt'));

    expect($robots)->toContain('User-agent: ' . $agent . "\n");
})->with([
    'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
    'ClaudeBot', 'Claude-User', 'Claude-SearchBot',
    'PerplexityBot', 'Perplexity-User',
    'Google-Extended', 'Applebot-Extended', 'CCBot',
]);

test('robots.txt keeps AI crawlers off the pages that cannot help them', function (): void {
    $ai = robotsGroup('CCBot');

    expect($ai)->toContain('Allow: /')
        ->and($ai)->toContain('Disallow: /register')
        ->and($ai)->toContain('Disallow: /p/');
});

test('robots.txt points at the sitemap on the production host', function (): void {
    expect((string) file_get_contents(public_path('robots.txt')))
        ->toContain('Sitemap: https://dipcatch.eu/sitemap.xml');
});

test('the sitemap serves XML that no session cookie rides along with', function (): void {
    $response = $this->get('/sitemap.xml')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=3600')
        ->and($response->headers->getCookies())->toBe([]);
});

test('the sitemap lists both representations of every marketing page', function (): void {
    $content = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    preg_match_all('#<loc>(.*?)</loc>#', $content, $matches);

    // Three fixed pages plus one per use-case page, each in two locales.
    expect($matches[1])->toHaveCount((3 + count(UseCases::all())) * 2)
        ->and($matches[1])->toContain(route('home'))
        ->and($matches[1])->toContain(route('home', ['lang' => 'nl']))
        ->and($matches[1])->toContain(route('pricing'))
        ->and($matches[1])->toContain(route('privacy', ['lang' => 'nl']));

    $base = Config::string('app.url');

    // A sitemap of relative URLs is invalid, so an unset APP_URL would make
    // the loop below pass while proving nothing.
    expect($base)->not->toBe('');

    foreach ($matches[1] as $loc) {
        expect(str_starts_with($loc, $base))->toBeTrue("{$loc} is not an absolute URL on {$base}");
    }
});

test('the sitemap leaves out everything that is noindex, blocked or pointless to list', function (string $fragment): void {
    $content = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($content)->not->toContain($fragment);
})->with(['register', 'login', '/p/', 'lang=en', 'invite']);

test('the sitemap repeats the hreflang alternates the pages themselves publish', function (): void {
    $content = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($content)
        ->toContain('hreflang="en" href="' . route('home') . '"')
        ->toContain('hreflang="nl" href="' . route('home', ['lang' => 'nl']) . '"')
        ->toContain('hreflang="x-default" href="' . route('home') . '"');
});

test('the sitemap dates the privacy page from the same value the page shows', function (): void {
    config()->set('site.privacy_updated_at', '2026-01-31');

    $content = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    expect(substr_count($content, '<lastmod>2026-01-31</lastmod>'))->toBe(2);
});

test('the sitemap omits lastmod when no privacy date is configured', function (): void {
    config()->set('site.privacy_updated_at', null);

    $content = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($content)->not->toContain('<lastmod>');
});

test('only the marketing pages are offered to crawlers as sitemap entries', function (): void {
    $names = array_map(static fn (array $page): string => $page[0], MarketingPages::routes());

    expect(array_values(array_unique($names)))->toBe(['home', 'pricing', 'privacy', 'use-case']);
});

test('the sitemap ignores a locale query and always lists both representations', function (): void {
    $bare = (string) $this->get('/sitemap.xml')->assertOk()->getContent();
    $dutch = (string) $this->get('/sitemap.xml?lang=nl')->assertOk()->getContent();

    expect($dutch)->toBe($bare);
});

test('blocking the app panel does not also block the apple touch icon', function (): void {
    // Robots rules are prefix matches, so a bare `Disallow: /app` would take
    // /apple-touch-icon.png with it.
    $robots = (string) file_get_contents(public_path('robots.txt'));

    expect($robots)->not->toContain("Disallow: /app\n")
        ->and($robots)->toContain('Disallow: /app/')
        ->and($robots)->toContain('Disallow: /app$');
});
