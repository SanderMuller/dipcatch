<?php declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\CheckjebonPrice;
use App\PriceAdapters\ShopSnapshot;
use App\Services\AhApi\AhApiSource;
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
 * The dataset carries no photograph and no way to tell a product still on the
 * shelf from one withdrawn years ago — its upstream JSON holds a name, a link,
 * a price and a size, and nothing else (checked against the published file on
 * 2026-09-22). Both gaps are closed by asking Albert Heijn's own API about each
 * candidate: it answers with the live price, the pack size and the product
 * photo, and answers 404 for anything out of assortment, which is exactly the
 * filter this needs. Ordering by id walks the oldest products first, so without
 * that filter most of what surfaced was discontinued.
 */
final class DatasetCatalog
{
    /** Shorter than the curated catalog: many products, not many rows each. */
    private const int HISTORY_DAYS = 30;

    /**
     * Products resolved against the AH API so far, in pool order.
     *
     * Held across calls because every account walks the same pool and the
     * modulus hands them the same products: without this, seeding five
     * accounts would ask the API the same questions five times.
     *
     * @var list<array{name: string, size: PackSize, ahPrice: string, ahLink: string, ahImage: ?string, sparPrice: string, sparLink: string}>
     */
    private static array $resolved = [];

    /** How far into the pairs the resolver has walked. */
    private static int $walked = 0;

    /**
     * Extra candidates per batch, to cover the ones Albert Heijn no longer
     * sells. Asking for a few more costs nothing — they travel together —
     * while coming up short costs another round of round trips.
     */
    private const int BATCH_SLACK = 8;

    /**
     * Products the dataset can furnish, or an empty list when it cannot.
     *
     * A checkout that has never run `dipcatch:refresh-checkjebon` has no rows,
     * and a seeder must still produce a populated app there — the caller falls
     * back to {@see GeneratedCatalog} on an empty answer.
     *
     * @return list<DemoProduct>
     */
    public static function make(int $count, int $offset = 0, bool $enrich = true): array
    {
        $pool = self::pool($offset + $count, $enrich);

        if ($pool === []) {
            return [];
        }

        $products = [];

        for ($index = 0; $index < $count; $index++) {
            // The position drives every variation below through a modulus, so
            // it must start at one: zero satisfies all of them at once, and
            // the first row of every account would be paused, dropped and
            // part sold out at the same time.
            $position = $offset + $index + 1;

            // Indexed by the absolute position, not the modulus of it: the
            // pool grows as later accounts ask for more, and a modulus against
            // a growing list hands two accounts the same product. It only
            // wraps when the dataset ran out of pairs before the pool was
            // filled, which is the one case where sharing is unavoidable.
            $products[] = self::product($pool[($offset + $index) % count($pool)], $position);
        }

        return $products;
    }

    /** Forget what the API answered, so a later seed asks again. */
    public static function forget(): void
    {
        self::$resolved = [];
        self::$walked = 0;
    }

    /**
     * At least `$needed` products the AH API still serves, or everything the
     * pairs can yield when it runs out first.
     *
     * Walks the pairs in order and stops as soon as it has enough, so a seed
     * spends one request per product it actually uses rather than one per
     * candidate. `$enrich` false skips the API entirely and trusts the
     * dataset — for a test that is exercising the pairing rather than the
     * network.
     *
     * @return list<array{name: string, size: PackSize, ahPrice: string, ahLink: string, ahImage: ?string, sparPrice: string, sparLink: string}>
     */
    private static function pool(int $needed, bool $enrich): array
    {
        $pairs = self::pairs();

        while (count(self::$resolved) < $needed && self::$walked < count($pairs)) {
            // A batch rather than one at a time: sixty-eight products cost one
            // round trip each, which was most of the seed's running time. The
            // batch is padded because some will be withdrawn and drop out, and
            // the loop goes round again if too many did.
            $batch = array_slice($pairs, self::$walked, max(1, $needed - count(self::$resolved)) + self::BATCH_SLACK);
            self::$walked += count($batch);

            foreach ($enrich ? self::resolveBatch($batch) : array_map(self::unresolved(...), $batch) as $entry) {
                self::$resolved[] = $entry;
            }
        }

        return self::$resolved;
    }

    /**
     * What Albert Heijn says about these products today, minus the ones it no
     * longer sells.
     *
     * The live price rather than the dataset's: a seeded history that ends on
     * a stale number makes the first real recheck read a fall that never
     * happened.
     *
     * @param  list<array{name: string, size: PackSize, ah: CheckjebonPrice, spar: CheckjebonPrice}>  $pairs
     * @return list<array{name: string, size: PackSize, ahPrice: string, ahLink: string, ahImage: ?string, sparPrice: string, sparLink: string}>
     */
    private static function resolveBatch(array $pairs): array
    {
        $urls = array_map(static fn (array $pair): string => self::ahUrl($pair), $pairs);
        $answers = app(AhApiSource::class)->resolveMany($urls);
        $entries = [];

        foreach ($pairs as $pair) {
            $snapshot = ($answers[self::ahUrl($pair)] ?? null)?->snapshot;

            if (! $snapshot instanceof ShopSnapshot) {
                continue;
            }

            $entries[] = [
                'name' => $snapshot->title,
                'size' => PackSize::resolve($snapshot->packSize, $snapshot->packSizeAuthoritative, $snapshot->title) ?? $pair['size'],
                'ahPrice' => $snapshot->price,
                'ahLink' => (string) $pair['ah']->link,
                'ahImage' => $snapshot->imageUrl,
                'sparPrice' => (string) $pair['spar']->price,
                'sparLink' => (string) $pair['spar']->link,
            ];
        }

        return $entries;
    }

    /**
     * @param  array{name: string, size: PackSize, ah: CheckjebonPrice, spar: CheckjebonPrice}  $pair
     */
    private static function ahUrl(array $pair): string
    {
        return 'https://www.ah.nl/producten/product/' . ltrim((string) $pair['ah']->link, '/');
    }

    /**
     * The dataset's own answer, for a caller that asked not to use the API.
     *
     * @param  array{name: string, size: PackSize, ah: CheckjebonPrice, spar: CheckjebonPrice}  $pair
     * @return array{name: string, size: PackSize, ahPrice: string, ahLink: string, ahImage: ?string, sparPrice: string, sparLink: string}
     */
    private static function unresolved(array $pair): array
    {
        return [
            'name' => $pair['name'],
            'size' => $pair['size'],
            'ahPrice' => (string) $pair['ah']->price,
            'ahLink' => (string) $pair['ah']->link,
            'ahImage' => null,
            'sparPrice' => (string) $pair['spar']->price,
            'sparLink' => (string) $pair['spar']->link,
        ];
    }

    /**
     * @param  array{name: string, size: PackSize, ahPrice: string, ahLink: string, ahImage: ?string, sparPrice: string, sparLink: string}  $entry
     */
    private static function product(array $entry, int $position): DemoProduct
    {
        $size = $entry['size'];

        return new DemoProduct(
            title: $entry['name'],
            offers: [
                new DemoOffer(
                    host: 'ah.nl',
                    path: '',
                    price: (float) $entry['ahPrice'],
                    packQuantity: $size->quantity,
                    packUnit: $size->unit,
                    realUrl: 'https://www.ah.nl/producten/product/' . ltrim($entry['ahLink'], '/'),
                    imageUrl: $entry['ahImage'],
                ),
                new DemoOffer(
                    host: 'spar.nl',
                    path: '',
                    price: (float) $entry['sparPrice'],
                    packQuantity: $size->quantity,
                    packUnit: $size->unit,
                    // Never the first offer: a product whose every offer is
                    // out of stock has no cheapest price, and then nothing
                    // downstream has a number to show.
                    state: self::state($position),
                    conditional: $position % 17 === 0,
                    promotion: $position % 13 === 0 ? 'live' : null,
                    realUrl: 'https://www.spar.nl/' . ltrim($entry['sparLink'], '/'),
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
