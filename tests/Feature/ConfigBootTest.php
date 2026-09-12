<?php declare(strict_types=1);

/**
 * A config file is evaluated before `key:generate` can run, so it must load on
 * an app that has no APP_KEY yet. `composer install` fires `package:discover`,
 * which boots the app and reads every config file — so a file that hard-fails
 * without APP_KEY aborts the install itself. That breaks `composer setup` on a
 * fresh clone (it installs before it generates the key) and every CI job that
 * installs dependencies, and the error names the config key rather than the
 * cause, so it reads as a broken lockfile.
 *
 * The trap is a strict read used as a default: `env('X', config()->string('k'))`
 * evaluates the default eagerly, and the strict accessor throws on null instead
 * of returning it. `config('k')` returns null and lets the fallback stand.
 */
test('every first-party config file loads when APP_KEY is not set yet', function (string $file): void {
    config()->set('app.key');

    // Required directly rather than wrapped in `expect(...)->not->toThrow()`:
    // that assertion passes even when the closure always throws. A throw here
    // fails the test on its own, which is the behaviour being guarded.
    $config = require config_path($file);

    expect($config)->toBeArray()->not->toBeEmpty();
})->with([
    'fortify.php',
    'dipcatch.php',
    'hsts.php',
    'plans.php',
    'scraper.php',
    'site.php',
]);

/**
 * The passkey user handle is derived from this secret, so a signed-in user's
 * passkeys stop resolving if it ever changes. It has to keep following APP_KEY
 * whenever the dedicated variable is unset — the keyless case above proves it
 * does not throw, and this proves the fallback still carries the real value.
 */
test('the passkey user handle secret falls back to the app key', function (): void {
    expect(config('fortify.passkeys.user_handle_secret'))->toBeString()->toBe(config('app.key'));
});
