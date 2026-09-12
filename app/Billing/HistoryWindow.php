<?php declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;

/**
 * How far back an account may read its price history.
 *
 * Kept out of the chart widget so the rule can be tested on its own: the
 * filter it receives is a public Livewire property, so its value arrives
 * from the client and the clamp here — not the menu — is what enforces the
 * plan.
 */
final class HistoryWindow
{
    public const string ALL = 'all';

    /**
     * Every range the chart can offer, longest last. PHP turns the numeric
     * keys into ints, which is why the maps below are keyed int|string.
     */
    private const array RANGES = [
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        '365' => 'Last 365 days',
        self::ALL => 'All time',
    ];

    /**
     * The ranges an account may pick. `$maxDays` null means unlimited.
     *
     * @return array<int|string, string>
     */
    public static function filters(?int $maxDays): array
    {
        if ($maxDays === null) {
            return self::RANGES;
        }

        $allowed = [];

        foreach (self::RANGES as $key => $label) {
            if ($key !== self::ALL && (int) $key <= $maxDays) {
                $allowed[$key] = $label;
            }
        }

        return $allowed;
    }

    /**
     * The cutoff for the chosen range, or null for everything ever stored.
     *
     * A plan with a ceiling never reaches null, however the filter got its
     * value.
     */
    public static function start(?int $maxDays, ?string $filter): ?CarbonImmutable
    {
        $filter ??= '90';

        if ($maxDays === null) {
            return $filter === self::ALL
                ? null
                : CarbonImmutable::now()->subDays(self::days($filter));
        }

        $days = $filter === self::ALL
            ? $maxDays
            : min(self::days($filter), $maxDays);

        return CarbonImmutable::now()->subDays($days);
    }

    private static function days(string $filter): int
    {
        return max(1, (int) $filter);
    }
}
