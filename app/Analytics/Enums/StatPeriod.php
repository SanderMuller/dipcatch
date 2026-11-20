<?php declare(strict_types=1);

namespace App\Analytics\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum StatPeriod: string
{
    case Week = 'week';
    case Month = 'month';

    /** Weeks start on Monday (ISO week). */
    public function startOf(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Week => $day->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $day->startOfMonth(),
        };
    }

    /** The first day after the period that starts on `$start`. */
    public function endAfter(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::Week => $start->addWeek(),
            self::Month => $start->addMonthNoOverflow(),
        };
    }

    public function label(CarbonImmutable $start): string
    {
        return match ($this) {
            self::Week => 'Week ' . $start->isoWeek() . ', ' . $start->isoWeekYear(),
            self::Month => $start->format('F Y'),
        };
    }
}
