<?php declare(strict_types=1);

use App\Services\ShopFetcher\RobotsTxtPolicy;
use Illuminate\Support\Facades\Config;

test('a shop operator who looks up the crawler finds a page explaining it', function (): void {
    $this->get('/bot')
        ->assertOk()
        ->assertSee('DipCatchBot')
        ->assertSee('robots.txt');
});

test('the page shows the exact user agent the fetcher sends', function (): void {
    config()->set('dipcatch.fetcher.user_agent', 'DipCatchBot/9.9 (+https://dipcatch.eu/bot)');

    $this->get('/bot')->assertOk()->assertSee('DipCatchBot/9.9 (+https://dipcatch.eu/bot)');
});

test('the page tells operators how to block the crawler', function (): void {
    $this->get('/bot')
        ->assertOk()
        ->assertSee('User-agent: DipCatchBot')
        ->assertSee('Disallow: /');
});

test('the crawl rate on the page follows the configured recheck interval', function (): void {
    config()->set('dipcatch.recheck.interval_hours', 12);

    $this->get('/bot')->assertOk()->assertSee('every 12 hours');
});

test('the page offers a contact address when one is configured', function (): void {
    config()->set('site.contact_email', 'hoi@dipcatch.eu');

    $this->get('/bot')->assertOk()->assertSee('hoi@dipcatch.eu');
});

test('the page shows no contact section when no address is configured', function (): void {
    config()->set('site.contact_email');

    $this->get('/bot')->assertOk()->assertDontSee('mailto:');
});

test('the bot page is reachable without signing in', function (): void {
    $this->get('/bot')->assertOk();
});

test('the user agent points at the domain the site actually runs on', function (): void {
    expect(Config::string('dipcatch.fetcher.user_agent'))->toContain('dipcatch.eu/bot');
});

test('the crawler sends the name it publishes, and obeys robots.txt under that name', function (): void {
    // These were three different strings: the requests impersonated Safari,
    // /bot published DipCatchBot, and the robots rules were matched against
    // the impersonated string — so an operator who followed the published
    // instruction and disallowed DipCatchBot was ignored.
    $sent = Config::string('dipcatch.fetcher.user_agent');

    $nameFromUa = new ReflectionMethod(RobotsTxtPolicy::class, 'nameFromUa');

    expect($sent)->toStartWith('DipCatchBot/')
        ->and($nameFromUa->invoke(null, $sent))->toBe('DipCatchBot')
        ->and($sent)->not->toContain('Mozilla');

    $this->get('/bot')->assertOk()->assertSee($sent);
});

test('no configuration or example env still names the old domain', function (string $path): void {
    expect((string) file_get_contents(base_path($path)))->not->toContain('dipcatch.app');
})->with(fn (): array => [
    ...array_map(fn (string $file): string => 'config/' . $file, firstPartyConfigFiles()),
    '.env.example',
]);

test('the advertised bot URL resolves to a real page', function (): void {
    // The user agent promises this path exists; a 404 there is what makes an
    // operator block the crawler outright.
    expect(route('bot'))->toEndWith('/bot');

    $this->get('/bot')->assertOk();
});
