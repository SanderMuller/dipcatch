<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Why a shop takes no part in a product's per-unit comparison.
 *
 * A closed set on purpose. A shop outside the comparison is still priced, still
 * shown and still competing for the lowest-price answer, so every surface has to
 * be able to say *why* it carries no unit price — and the copy stays finite and
 * actionable only while the reasons are enumerated here rather than invented at
 * each surface.
 */
enum PackExclusion: string
{
    /** No size on the page, and the shops that do state one disagree. */
    case SizeUnknown = 'size_unknown';

    /** A count with no item size anywhere on the shop's own page. */
    case SoldByThePiece = 'sold_by_the_piece';

    /** A size so far below the field that it is read as wrong, not as cheap. */
    case SizeImplausible = 'size_implausible';

    /** A real unit that does not convert into the product's own. */
    case UnitDoesNotConvert = 'unit_does_not_convert';

    /** Priced in a currency the product does not compare in. */
    case DifferentCurrency = 'different_currency';

    /**
     * One sentence a shopper can act on. `$currency` is only read by
     * {@see self::DifferentCurrency}; the others ignore it.
     */
    public function label(?string $currency = null): string
    {
        return match ($this) {
            self::SizeUnknown => 'Pack size unknown',
            self::SoldByThePiece => 'Sold by the piece — no item size to compare',
            self::SizeImplausible => 'Pack size looks wrong for this product',
            self::UnitDoesNotConvert => 'Measured in a different unit',
            self::DifferentCurrency => 'Priced in ' . ($currency ?? 'another currency'),
        };
    }
}
