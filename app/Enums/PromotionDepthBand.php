<?php declare(strict_types=1);

namespace App\Enums;

/**
 * How deep a product's promotions usually go: the options of the Choice Jev
 * is asked for a suggested alert. `Fixed` is a product that may not or does
 * not go on sale; `Unknown` is Jev having no answer, which hands the decision
 * to the category table.
 */
enum PromotionDepthBand: string
{
    case HalfOrMore = 'half_or_more';
    case Deep = 'deep';
    case Moderate = 'moderate';
    case Small = 'small';
    case Fixed = 'fixed';
    case Unknown = 'unknown';

    /** The depth in whole percent, or null for `Unknown`. */
    public function depth(): ?int
    {
        return match ($this) {
            self::HalfOrMore => 50,
            self::Deep => 35,
            self::Moderate => 25,
            self::Small => 15,
            self::Fixed => 0,
            self::Unknown => null,
        };
    }

    /** What the option means, in English, for the Choice Jev is asked. */
    public function rubric(): string
    {
        return match ($this) {
            self::HalfOrMore => 'Regularly on 1+1 free, 2+2 or 2+3 free at these shops, about half off per item: A-brand drugstore items, laundry and cleaning products, nappies, cola.',
            self::Deep => 'Weekly supermarket promotions such as 2nd half price, 2+1 or now and then 1+1, about a third off: A-brand food, coffee, snacks.',
            self::Moderate => 'Seasonal or event sales of about a quarter off: clothing at the end of a season, toys before December, small appliances or audio on Black Friday.',
            self::Small => 'Rarely discounted, or plain price cuts of 10 to 20 percent: large appliances, phones, discount-chain own brands.',
            self::Fixed => 'May not or does not go on sale: a Dutch-language paper book (fixed book price), first infant formula (promotions are banned).',
            self::Unknown => 'None of the other options fits, or there is not enough to tell.',
        };
    }
}
