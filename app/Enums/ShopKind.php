<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Whether DipCatch reads this shop's price, or only remembers that it sells
 * the product.
 *
 * Finding which shops sell a given thing is the slow part of tracking it —
 * searches, titles, pack sizes, ruling out bundles and starter kits. When one
 * of them refused to be read, that work was thrown away: the URL was verified
 * as the right product at the right size, and then discarded, because a shop
 * with no price had nowhere to live. The next session ran the same searches
 * and hit the same walls.
 *
 * The blocked set is not a random sample either. It is the largest retailers
 * in the market, which are the ones running the biggest promotions — so the
 * app was blind where a drop is most likely.
 *
 * A reference shop is that research, kept. It holds a URL and nothing that
 * looks like a price, so it can never reach either answer, and it is retried
 * on a slow schedule: a block is a fact about today, not a permanent one.
 */
enum ShopKind: string
{
    /** Read on a schedule; its price decides the answers. */
    case Tracked = 'tracked';

    /** A link, kept because the page could not be read. */
    case Reference = 'reference';

    /** One sentence a shopper can act on, or null when nothing needs saying. */
    public function note(): ?string
    {
        return match ($this) {
            self::Tracked => null,
            self::Reference => 'DipCatch cannot read this shop — open it to check the price yourself',
        };
    }
}
