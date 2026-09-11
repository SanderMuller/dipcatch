<?php declare(strict_types=1);

/**
 * `.env.example` drifts silently: a new `env()` call in a config file ships,
 * the deploy comes up without the variable, and nothing says so. Web push and
 * mail both fail that way — no health check covers either — so the app reports
 * healthy while two features are dead.
 *
 * The guard covers the config files DipCatch itself authors. Package config
 * (`cashier.php`, `webpush.php`, `health.php`, and the rest) is deliberately
 * out of scope: those files carry dozens of knobs for drivers this app does
 * not use, and documenting them would bury the handful that matter. The
 * app-specific names that do live in package config — `RESEND_API_KEY`,
 * `VAPID_PEM_FILE`, `STRIPE_WEBHOOK_TOLERANCE`, `FAILED_JOB_CHANNELS` — are
 * documented in `.env.example` but are not guarded here.
 *
 * The test reads variable NAMES only. It never reads a value, and it never
 * opens `.env`.
 */
test('every environment variable the app reads from its own config is documented in .env.example', function (): void {
    $firstPartyConfig = [
        'config/dipcatch.php',
        'config/hsts.php',
        'config/plans.php',
        'config/scraper.php',
        'config/site.php',
    ];

    $read = [];

    foreach ($firstPartyConfig as $relativePath) {
        $contents = (string) file_get_contents(base_path($relativePath));

        preg_match_all('/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $contents, $matches);

        $read = [...$read, ...$matches[1]];
    }

    sort($read);

    // A commented-out line documents the variable just as well as a set one:
    // `# CACHE_PREFIX=` tells a reader the knob exists and is off by default.
    $example = (string) file_get_contents(base_path('.env.example'));
    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', $example, $documented);

    $undocumented = array_values(array_diff(array_unique($read), $documented[1]));

    expect($undocumented)->toBe([], sprintf(
        'Read by %s but missing from .env.example: %s',
        implode(', ', $firstPartyConfig),
        implode(', ', $undocumented),
    ));
});
