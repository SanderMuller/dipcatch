<?php declare(strict_types=1);

/**
 * Presentation-layer tripwire. Not a guarantee — the per-surface feature tests
 * are the real coverage. This only catches a *new* hand-rolled money string in
 * code that renders to a user, so it never silently diverges from
 * `MoneyFormatter`.
 *
 * Non-presentation code (app/Actions, app/Console, app/Services, app/Support)
 * is out of scope: it formats numbers for matching, parsing or storage.
 */

use Symfony\Component\Finder\Finder;

/**
 * Directories that render money to a user.
 *
 * @return list<string>
 */
function presentationPaths(): array
{
    return [
        'app/Filament',
        'app/Livewire',
        'app/Notifications',
        'app/Mail',
        'resources/views',
    ];
}

/**
 * Hand-rolled money patterns. Anything matching must either go through
 * `MoneyFormatter` or earn an allowlist entry below.
 *
 * @return list<string>
 */
function prohibitedMoneyPatterns(): array
{
    return [
        'number_format(',
        "['currency'] }} {{",
        '->currency }} {{',
        "->currency . ' '",
        "['currency'] . ' '",
    ];
}

/**
 * Allowed matches, keyed by file basename. Each entry names a source fragment
 * that must appear in the matching line, plus the reason it is not money.
 * A fragment that no longer matches anything fails this test — stale entries
 * do not survive.
 *
 * @return array<string, list<array{fragment: string, reason: string}>>
 */
function tripwireAllowlist(): array
{
    return [
        'CreateProductFromUrl.php' => [
            [
                'fragment' => 'number_format($defaults[',
                'reason' => 'serialises threshold decimals into form state, not display',
            ],
        ],
        'price-drop-digest.blade.php' => [
            [
                'fragment' => 'number_format((float) $event->drop_pct',
                'reason' => 'percentage, not money',
            ],
        ],
    ];
}

/**
 * @return list<array{file: string, basename: string, line: int, source: string}>
 */
function moneyTripwireMatches(): array
{
    $root = base_path();

    $finder = new Finder()
        ->files()
        ->in(array_map(fn (string $path): string => $root . '/' . $path, presentationPaths()))
        ->name(['*.php']);

    $matches = [];

    foreach ($finder as $file) {
        $relative = str_replace($root . '/', '', (string) $file->getRealPath());
        $lines = explode("\n", (string) file_get_contents((string) $file->getRealPath()));

        foreach ($lines as $index => $source) {
            foreach (prohibitedMoneyPatterns() as $pattern) {
                if (! str_contains($source, $pattern)) {
                    continue;
                }

                $matches[] = [
                    'file' => $relative,
                    'basename' => basename($relative),
                    'line' => $index + 1,
                    'source' => trim($source),
                ];

                break;
            }
        }
    }

    return $matches;
}

test('presentation code never hand-rolls a money string', function (): void {
    $offenders = [];

    foreach (moneyTripwireMatches() as $match) {
        $allowed = false;

        foreach (tripwireAllowlist()[$match['basename']] ?? [] as $entry) {
            if (str_contains($match['source'], $entry['fragment'])) {
                $allowed = true;

                break;
            }
        }

        if (! $allowed) {
            $offenders[] = $match['file'] . ':' . $match['line'] . ' — ' . $match['source'];
        }
    }

    expect($offenders)->toBe(
        [],
        'Hand-rolled money in presentation code. Route it through App\\Support\\MoneyFormatter, '
        . "or add an allowlist entry with a reason in tests/Feature/Support/MoneyPresentationTripwireTest.php:\n"
        . implode("\n", $offenders),
    );
});

test('every tripwire allowlist entry still matches real source', function (): void {
    $matches = moneyTripwireMatches();
    $stale = [];

    foreach (tripwireAllowlist() as $basename => $entries) {
        foreach ($entries as $entry) {
            $used = false;

            foreach ($matches as $match) {
                if ($match['basename'] === $basename && str_contains($match['source'], $entry['fragment'])) {
                    $used = true;

                    break;
                }
            }

            if (! $used) {
                $stale[] = $basename . ' / ' . $entry['fragment'];
            }
        }
    }

    expect($stale)->toBe([], "Stale tripwire allowlist entries — the code they excuse is gone:\n" . implode("\n", $stale));
});
