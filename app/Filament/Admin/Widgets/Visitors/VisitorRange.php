<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use Carbon\CarbonImmutable;
use Throwable;

final readonly class VisitorRange
{
    public const string DEFAULT_PRESET = '30';

    /** @var array<int|string, string> */
    public const array PRESETS = [
        '7' => 'Last 7 days',
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        'custom' => 'Custom range',
    ];

    public function __construct(public CarbonImmutable $from, public CarbonImmutable $until) {}

    /**
     * @param  array<array-key, mixed>|null  $filters
     */
    public static function fromFilters(?array $filters): self
    {
        $today = now()->toImmutable()->startOfDay();
        $preset = $filters['range'] ?? self::DEFAULT_PRESET;

        if ($preset === 'custom') {
            $from = self::date($filters['from'] ?? null);
            $until = self::date($filters['until'] ?? null) ?? $today;

            if ($from !== null) {
                $until = $until->min($today);

                return $from->greaterThan($until) ? new self($until, $from->min($today)) : new self($from, $until);
            }

            $preset = self::DEFAULT_PRESET;
        }

        $days = is_string($preset) && ctype_digit($preset) ? max(1, (int) $preset) : (int) self::DEFAULT_PRESET;

        return new self($today->subDays($days - 1), $today);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
