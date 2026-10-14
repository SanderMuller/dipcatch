<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Today's price at a shop against that shop's own last 90 days. See
 * specs/buy-now-or-wait.md, section 2.
 */
enum PriceVerdict: string
{
    case Good = 'good';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Good => __('Good price'),
            self::Normal => __('Normal price'),
            self::High => __('High price'),
        };
    }

    /** A Heroicon, so the label is told apart from the other badges without reading it. */
    public function icon(): string
    {
        return match ($this) {
            self::Good => 'arrow-trending-down',
            self::Normal => 'equals',
            self::High => 'arrow-trending-up',
        };
    }

    /** The Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Good => 'green',
            self::Normal => 'zinc',
            self::High => 'amber',
        };
    }
}
