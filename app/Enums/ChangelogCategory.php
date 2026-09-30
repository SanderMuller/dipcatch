<?php declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of entry on the "What's new" page.
 */
enum ChangelogCategory: string
{
    case Feature = 'feature';
    case Shop = 'shop';
    case Pro = 'pro';
    case Fix = 'fix';

    public function label(): string
    {
        return match ($this) {
            self::Feature => __('New'),
            self::Shop => __('New shop'),
            self::Pro => __('Pro'),
            self::Fix => __('Fix'),
        };
    }

    /**
     * The `flux:badge` color.
     */
    public function color(): string
    {
        return match ($this) {
            self::Feature => 'sky',
            self::Shop => 'emerald',
            self::Pro => 'amber',
            self::Fix => 'zinc',
        };
    }
}
