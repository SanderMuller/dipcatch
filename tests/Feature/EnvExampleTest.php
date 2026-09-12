<?php declare(strict_types=1);

/**
 * `.env.example` drifts silently: a new `env()` call in a config file ships,
 * the deploy comes up without the variable, and nothing says so. Web push and
 * mail both fail that way — no health check covers either — so the app reports
 * healthy while two features are dead.
 *
 * The guard covers the config files DipCatch itself authors. Add a new one to
 * `$firstPartyConfig` below, or the guard silently stops covering it. Package
 * config is out of scope: those files carry dozens of knobs for drivers this
 * app does not use, and documenting them would bury the handful that matter.
 * App-specific names that live in package config are documented but unguarded.
 */
test('every environment variable the app reads from its own config is documented in .env.example', function (): void {
    $firstPartyConfig = [
        'config/dipcatch.php',
        'config/hsts.php',
        'config/plans.php',
        'config/scraper.php',
        'config/site.php',
    ];

    // Keyed by name so the failure can say which file reads it. The pattern
    // takes any identifier rather than uppercase-only, so a name that breaks
    // the SCREAMING_CASE convention is still guarded.
    $read = [];

    foreach ($firstPartyConfig as $relativePath) {
        $contents = (string) file_get_contents(base_path($relativePath));

        preg_match_all('/env\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $contents, $matches);

        foreach ($matches[1] as $name) {
            $read[$name] ??= $relativePath;
        }
    }

    ksort($read);

    // A commented-out line documents the variable just as well as a set one:
    // `# CACHE_PREFIX=` tells a reader the knob exists and is off by default.
    // The line must be an assignment and nothing else, so that prose which
    // happens to mention `NAME=value` mid-sentence does not count as
    // documentation. A quoted value may contain spaces; a bare one may not.
    $example = (string) file_get_contents(base_path('.env.example'));
    preg_match_all('/^#?\s*([A-Za-z_][A-Za-z0-9_]*)=(?:"[^"]*"|\S*)\s*$/m', $example, $documented);

    $undocumented = array_diff_key($read, array_flip($documented[1]));

    $report = array_map(
        static fn (string $file, string $name): string => "{$name} (read in {$file})",
        $undocumented,
        array_keys($undocumented),
    );

    expect($report)->toBe([], 'Missing from .env.example: ' . implode(', ', $report));
});
