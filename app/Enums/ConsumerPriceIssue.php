<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Why a number read off a shop's page is not a price a shopper can pay.
 *
 * Distinct from {@see PackExclusion}, and the distinction is the whole point:
 * a pack exclusion keeps a shop competing for the lowest-price answer and only
 * bars it from the per-unit one. A shop here is barred from both, because the
 * figure is not a smaller price — it is not the shopper's price at all. Left
 * in, it wins on being incomplete.
 *
 * A closed set, for the same reason `PackExclusion` is one: every surface has
 * to be able to say which fact disqualified the row.
 */
enum ConsumerPriceIssue: string
{
    /** Quoted before tax, so it is short by the VAT rate. */
    case ExcludesVat = 'excludes_vat';

    /** Shown only to trade accounts; the page sells nothing to the public. */
    case TradeOnly = 'trade_only';

    /** One sentence a shopper can act on. */
    public function label(): string
    {
        return match ($this) {
            self::ExcludesVat => 'Price excludes VAT — not comparable',
            self::TradeOnly => 'Trade-only — not sold to consumers',
        };
    }
}
