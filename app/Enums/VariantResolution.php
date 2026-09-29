<?php declare(strict_types=1);

namespace App\Enums;

/**
 * How a reader decided which variant of a multi-variant page it priced.
 *
 * A page selling three flavours returned one price and said nothing about the
 * other two. Confirming it tracked whichever variant the page defaults to
 * inside a product named after a different one — and because all three cost
 * the same that day, no number was wrong yet. The failure waits for the first
 * flavour-specific promotion, which is the kind that stays hidden.
 *
 * So the pick is stated rather than implied. A caller can tell a page with one
 * variant from a page where one of several was chosen, and tell a choice the
 * request made from a default it was handed.
 */
enum VariantResolution: string
{
    /** The URL named this variant — the caller asked for it. */
    case Url = 'url';

    /** A `variant_key` the caller pinned matched it. */
    case VariantKey = 'variant_key';

    /** The page sells one thing. Nothing was chosen, and nothing could be. */
    case OnlyVariant = 'only_variant';

    /**
     * Several variants and nothing named one, so the page's default was read.
     * Never on a probe: a caller adding a shop gets the chooser instead. Only
     * a recheck of a shop saved before its reader could see variants reads
     * this, so the shop keeps the price it always had instead of failing.
     */
    case PageDefault = 'page_default';

    /** One sentence a caller can act on. */
    public function note(int $variants): string
    {
        return match ($this) {
            self::OnlyVariant => 'This page sells one variant.',
            self::Url => 'This page sells ' . $variants . ' variants; the URL names this one.',
            self::VariantKey => 'This page sells ' . $variants . ' variants; variant_key names this one.',
            self::PageDefault => 'This page sells ' . $variants . ' variants; none was named, so this is the page default.',
        };
    }
}
