<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ShopKind;
use App\Models\Product;
use App\Models\Shop;

/**
 * The shops on a product that DipCatch cannot read, named at the moment
 * somebody is about to buy.
 *
 * An alert is the one moment the reader is going to open a tab anyway. "Lungo
 * XL dropped to 0.24 a cup at Amazon" is more use ending with the two or three
 * places worth opening by hand, because those are often the largest retailers
 * in the market — the ones this app cannot read and the ones running the
 * biggest promotions.
 *
 * Hosts, never prices. A reference shop holds no figure, and naming it beside
 * one that does must not suggest otherwise.
 */
final readonly class AlsoWorthChecking
{
    /**
     * Enough to be worth a look, few enough to read in a notification.
     */
    private const int MAX = 3;

    /**
     * @return list<array{host: string, url: string}>
     */
    public static function of(Product $product): array
    {
        return $product->shops()
            ->where('kind', ShopKind::Reference->value)
            ->where('active', true)
            ->orderBy('host')
            ->limit(self::MAX)
            ->get()
            ->map(static fn (Shop $shop): array => ['host' => (string) $shop->host, 'url' => (string) $shop->url])
            ->values()
            ->all();
    }

    /**
     * One sentence, or null when the product has no such shops — so a caller
     * can append it without deciding whether there is anything to append.
     *
     * @param  list<array{host: string, url: string}>  $shops
     */
    public static function line(array $shops): ?string
    {
        if ($shops === []) {
            return null;
        }

        return 'Also worth checking by hand: '
            . implode(', ', array_map(static fn (array $shop): string => $shop['host'], $shops));
    }
}
