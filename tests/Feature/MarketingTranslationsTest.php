<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Assert;

test('the Dutch marketing pages render no untranslated string', function (): void {
    // Every `__()` key the two routes miss in `lang/nl.json` lands here, so
    // this covers the views, their partials and their components at once.
    $missing = [];

    Lang::handleMissingKeysUsing(function (string $key) use (&$missing): void {
        $missing[$key] = true;
    });

    try {
        config()->set('site.contact_email', 'hello@example.test');

        // Guests and members see different copy, so drive both.
        $this->get(route('home', ['lang' => 'nl']))->assertOk();
        $this->get(route('privacy', ['lang' => 'nl']))->assertOk();
        $this->get(route('pricing', ['lang' => 'nl']))->assertOk();

        // Slugs from config, not UseCases::all(): building the objects here
        // evaluates their __() copy while the locale is still English, and the
        // handler above would report every one of those keys as missing.
        foreach (array_keys(Config::array('site.use_cases')) as $slug) {
            $this->get(route('use-case', ['slug' => $slug, 'lang' => 'nl']))->assertOk();
        }

        $this->actingAs(User::factory()->create());

        $this->get(route('home', ['lang' => 'nl']))->assertOk();
        $this->get(route('privacy', ['lang' => 'nl']))->assertOk();
        $this->get(route('pricing', ['lang' => 'nl']))->assertOk();

        // Slugs from config, not UseCases::all(): building the objects here
        // evaluates their __() copy while the locale is still English, and the
        // handler above would report every one of those keys as missing.
        foreach (array_keys(Config::array('site.use_cases')) as $slug) {
            $this->get(route('use-case', ['slug' => $slug, 'lang' => 'nl']))->assertOk();
        }
    } finally {
        Lang::handleMissingKeysUsing(null);
    }

    Assert::assertSame(
        [],
        array_keys($missing),
        'The Dutch marketing pages fall back to English for these keys; add them to lang/nl.json.',
    );
});

test('lang/nl.json carries no key the marketing views no longer use', function (): void {
    // The marketing views plus everything they include. The views write
    // every translated string as a single-quoted, single-line `__('…')`
    // literal, so a verbatim substring match is exact.
    $files = [
        resource_path('views/welcome.blade.php'),
        resource_path('views/privacy.blade.php'),
        resource_path('views/pricing.blade.php'),
        resource_path('views/support.blade.php'),
        resource_path('views/terms.blade.php'),
        resource_path('views/partials/head.blade.php'),
        resource_path('views/components/appearance-toggle.blade.php'),
        resource_path('views/components/marketing-header.blade.php'),
        resource_path('views/components/marketing-footer-links.blade.php'),
        resource_path('views/use-case.blade.php'),
        resource_path('views/shop.blade.php'),
        resource_path('views/shops.blade.php'),
        resource_path('views/components/marketing-header/language.blade.php'),
        // Auth views are not locale-switchable yet (no MarketingLocale
        // middleware on the Fortify routes), but their strings go through
        // __() and carry Dutch entries, so list them here or every one of
        // those keys reads as an orphan.
        resource_path('views/livewire/auth/confirm-password.blade.php'),
        resource_path('views/livewire/auth/forgot-password.blade.php'),
        resource_path('views/livewire/auth/login.blade.php'),
        resource_path('views/livewire/auth/register.blade.php'),
        resource_path('views/livewire/auth/reset-password.blade.php'),
        resource_path('views/livewire/auth/two-factor-challenge.blade.php'),
        resource_path('views/livewire/auth/verify-email.blade.php'),
        resource_path('views/auth/invitation.blade.php'),
        resource_path('views/bot.blade.php'),
        // Error pages render outside the `web` group, so they are always
        // English. Their strings still go through __() and carry Dutch
        // entries, so list them or every one of those keys reads as an orphan.
        resource_path('views/errors/404.blade.php'),
        resource_path('views/errors/500.blade.php'),
        resource_path('views/llms.blade.php'),
        // Not a view: the marketing pages' JSON-LD is built in PHP, because
        // Laravel 13 compiles a literal `@context` key in a Blade array as a
        // context directive. Its strings still render on the Dutch page.
        app_path('Support/StructuredData.php'),
        // Same reason: the use-case pages' copy is written out per slug in PHP
        // so each page reads as its own text rather than a filled template.
        app_path('Support/UseCases.php'),
        // Same again for the per-shop pages: their copy is assembled in PHP
        // from what each adapter can actually read.
        app_path('Support/ShopPages.php'),
    ];

    $sources = array_map(static fn (string $file): string => (string) file_get_contents($file), $files);

    $translations = json_decode((string) file_get_contents(lang_path('nl.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($translations)->toBeArray()->not->toBeEmpty();
    assert(is_array($translations));

    $orphans = [];

    foreach (array_keys($translations) as $key) {
        $literal = "__('" . $key . "'";

        foreach ($sources as $source) {
            if (str_contains($source, $literal)) {
                continue 2;
            }
        }

        $orphans[] = $key;
    }

    Assert::assertSame(
        [],
        $orphans,
        'lang/nl.json holds keys no marketing view renders: ' . implode(' | ', $orphans),
    );
});

test('the Dutch translations keep every placeholder of their English key', function (): void {
    $translations = json_decode((string) file_get_contents(lang_path('nl.json')), true, 512, JSON_THROW_ON_ERROR);

    assert(is_array($translations));

    foreach ($translations as $key => $value) {
        assert(is_string($key));
        expect($value)->toBeString();
        assert(is_string($value));

        preg_match_all('/:[a-zA-Z]+/', $key, $expected);
        preg_match_all('/:[a-zA-Z]+/', $value, $actual);

        $dropped = array_values(array_diff($expected[0], $actual[0]));

        Assert::assertSame([], $dropped, "The Dutch translation of \"{$key}\" drops " . implode(', ', $dropped));
    }
});
