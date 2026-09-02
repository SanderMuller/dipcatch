<?php declare(strict_types=1);

use App\Models\User;
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

        $this->actingAs(User::factory()->create());

        $this->get(route('home', ['lang' => 'nl']))->assertOk();
        $this->get(route('privacy', ['lang' => 'nl']))->assertOk();
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
    // The two marketing views plus everything they include. The views write
    // every translated string as a single-quoted, single-line `__('…')`
    // literal, so a verbatim substring match is exact.
    $files = [
        resource_path('views/welcome.blade.php'),
        resource_path('views/privacy.blade.php'),
        resource_path('views/partials/head.blade.php'),
        resource_path('views/components/appearance-toggle.blade.php'),
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
