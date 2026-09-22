<?php declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\CheckjebonPrice;
use App\Services\Checkjebon\CheckjebonSource;
use App\Support\PackSize;
use Illuminate\Support\Collection;

/**
 * Bulk demo products drawn from the local checkjebon.nl dataset — real
 * groceries, at addresses that answer, priced at what the two chains were
 * charging when the dataset was last refreshed.
 *
 * {@see GeneratedCatalog} invents its products, so a recheck of one reads a
 * 404 and every price stands still forever. The dataset already holds tens of
 * thousands of real products with a real name, a real price and a real pack
 * size, and two of its chains build a product URL that can be reconstructed
 * from the row: Albert Heijn as `producten/product/{link}` and SPAR as the
 * link itself. Both were verified against the live sites on 2026-09-22.
 *
 * Albert Heijn is the first offer on every product on purpose:
 * {@see CheckjebonSource} resolves an ah.nl URL out
 * of this same table, so rechecking a seeded account needs no network at all.
 * The SPAR offer is scraped like any other shop, which is what makes the
 * comparison — two chains, two real prices, one product — worth having.
 *
 * Only products both chains sell under the same name, which is a few hundred
 * rather than the whole dataset. An exact name match is a blunt instrument and
 * a deliberate one: a fuzzy match would pair a 500 g bag with a 1 kg bag and
 * quietly poison the per-unit column, which is the thing a demo is supposed to
 * demonstrate.
 *
 * The dataset carries no photographs, so these products show the stand-in
 * {@see DemoProduct::image()} builds until their first real recheck reads one
 * off the shop's own page.
 */
final readonly class DatasetCatalog
{
    /** Shorter than the curated catalog: many products, not many rows each. */
    private const int HISTORY_DAYS = 30;

    /**
     * Products the dataset can furnish, or an empty list when it cannot.
     *
     * A checkout that has never run `dipcatch:refresh-checkjebon` has no rows,
     * and a seeder must still produce a populated app there — the caller falls
     * back to {@see GeneratedCatalog} on an empty answer.
     *
     * @return list<DemoProduct>
     */
    public static function make(int $count, int $offset = 0): array
    {
        $pairs = self::pairs();

        if ($pairs === []) {
            return [];
        }

        $products = [];

        for ($index = 0; $index < $count; $index++) {
            // The position drives every variation below through a modulus, so
            // it must start at one: zero satisfies all of them at once, and
            // the first row of every account would be paused, dropped and
            // part sold out at the same time.
            $position = $offset + $index + 1;
            $products[] = self::product($pairs[$position % count($pairs)], $position);
        }

        return $products;
    }

    /**
     * @param  array{name: string, size: PackSize, ah: CheckjebonPrice, spar: CheckjebonPrice}  $pair
     */
    private static function product(array $pair, int $position): DemoProduct
    {
        $size = $pair['size'];

        return new DemoProduct(
            title: $pair['name'],
            offers: [
                new DemoOffer(
                    host: 'ah.nl',
                    path: '',
                    price: (float) $pair['ah']->price,
                    packQuantity: $size->quantity,
                    packUnit: $size->unit,
                    realUrl: 'https://www.ah.nl/producten/product/' . ltrim((string) $pair['ah']->link, '/'),
                ),
                new DemoOffer(
                    host: 'spar.nl',
                    path: '',
                    price: (float) $pair['spar']->price,
                    packQuantity: $size->quantity,
                    packUnit: $size->unit,
                    // Never the first offer: a product whose every offer is
                    // out of stock has no cheapest price, and then nothing
                    // downstream has a number to show.
                    state: self::state($position),
                    conditional: $position % 17 === 0,
                    promotion: $position % 13 === 0 ? 'live' : null,
                    realUrl: 'https://www.spar.nl/' . ltrim((string) $pair['spar']->link, '/'),
                ),
            ],
            active: $position % 19 !== 0,
            drop: match (true) {
                $position % 11 === 0 => 'today',
                $position % 7 === 0 => 'recent',
                $position % 5 === 0 => 'week',
                default => null,
            },
            historyDays: self::HISTORY_DAYS,
            ageDays: fake()->numberBetween(self::HISTORY_DAYS + 2, 240),
        );
    }

    /**
     * Only the ordinary states here. A failing or a dead offer keeps its
     * curated home, where the catalog says out loud which one it is.
     */
    private static function state(int $position): string
    {
        return match (true) {
            $position % 23 === 0 => 'out_of_stock',
            $position % 29 === 0 => 'unknown_stock',
            default => 'ok',
        };
    }

    /**
     * Every product both chains sell under the same name, with a pack size
     * this app can read.
     *
     * Ordered by the Albert Heijn id so the walk is the same on every machine
     * that holds the same dataset: an account's list is allowed to change when
     * the dataset is refreshed, but not between two seeds of one checkout.
     *
     * @return list<array{name: string, size: PackSize, ah: CheckjebonPrice, spar: CheckjebonPrice}>
     */
    private static function pairs(): array
    {
        $spar = self::rowsByName('spar');

        if ($spar->isEmpty()) {
            return [];
        }

        $pairs = [];

        foreach (self::rows('ah') as $row) {
            $key = self::nameKey($row->name);
            $match = $spar->get($key);
            $size = PackSize::parse($row->size);

            // A size this app cannot read leaves the product with no unit
            // price, and a bulk row exists to fill the comparison rather than
            // to pose a question about it.
            if (! $match instanceof CheckjebonPrice || ! $size instanceof PackSize) {
                continue;
            }

            $pairs[] = ['name' => $row->name, 'size' => $size, 'ah' => $row, 'spar' => $match];
        }

        return $pairs;
    }

    /**
     * @return Collection<string, CheckjebonPrice>
     */
    private static function rowsByName(string $supermarket): Collection
    {
        return self::rows($supermarket)->keyBy(fn (CheckjebonPrice $row): string => self::nameKey($row->name));
    }

    /**
     * @return Collection<int, CheckjebonPrice>
     */
    private static function rows(string $supermarket): Collection
    {
        return CheckjebonPrice::query()
            ->where('supermarket', $supermarket)
            ->whereNotNull('link')
            ->where('price', '>', 0)
            ->orderBy('external_id')
            ->get();
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
