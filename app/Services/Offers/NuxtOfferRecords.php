<?php declare(strict_types=1);

namespace App\Services\Offers;

use App\PriceAdapters\PriceNormalizer;
use App\Support\DutchDate;
use App\Support\NuxtData;

/**
 * Reads the offer records of a Dirk or DekaMarkt offers page into the
 * offers each product has announced.
 */
final readonly class NuxtOfferRecords
{
    /**
     * The offer each product has announced, or null for a product two
     * announced offers disagree on. A started offer is left out before that
     * comparison: the current offers page lists a running offer beside next
     * week's. A product's own days win: DekaMarkt's product records start a
     * day before its folder, and its site shows the offer from then (seen on
     * Monday 26 October 2026). Without them the days are the disclaimer pair:
     * a Dirk weekend offer is listed from Wednesday but runs from Friday, and
     * only the date part names the retail day. A product's own `offerPrice`
     * is its price, not the offer's headline (Dirk's ginger: 2.49 headline,
     * 4.98 product).
     *
     * @param  list<mixed>  $data
     * @return array<string, ?AnnouncedOffer> product id => offer, null on a conflict
     */
    public static function parse(array $data, bool $readsText = true): array
    {
        $offers = [];

        foreach ($data as $record) {
            if (! is_array($record) || ! isset($record['offerId'], $record['products'], $record['disclaimerStartDate'], $record['disclaimerEndDate'])) {
                continue;
            }

            /** @var array<string, mixed> $record */
            $startsAt = DutchDate::startOfDay(self::day(NuxtData::value($data, $record, 'disclaimerStartDate')));
            $endsAt = DutchDate::endOfDay(self::day(NuxtData::value($data, $record, 'disclaimerEndDate')));
            $text = NuxtData::value($data, $record, 'textPriceSign');
            $products = NuxtData::value($data, $record, 'products');

            if ($startsAt === null || $endsAt === null || ! is_array($products)) {
                continue;
            }

            $offer = new AnnouncedOffer(startsAt: $startsAt, endsAt: $endsAt, offerPrice: null, text: $readsText && is_string($text) ? $text : null);

            foreach (self::products($data, $products, $offer) as $productId => $productOffer) {
                if ($productOffer->startsAt->isFuture()) {
                    $offers[$productId] = array_key_exists($productId, $offers) && ! self::same($offers[$productId], $productOffer) ? null : $productOffer;
                }
            }
        }

        return $offers;
    }

    /**
     * @param  list<mixed>  $data
     * @param  array<mixed>  $indexes
     * @return array<string, AnnouncedOffer> product id => the offer with the product's own price and days
     */
    private static function products(array $data, array $indexes, AnnouncedOffer $offer): array
    {
        $products = [];

        foreach ($indexes as $index) {
            $product = is_int($index) ? ($data[$index] ?? null) : null;

            if (! is_array($product)) {
                continue;
            }

            /** @var array<string, mixed> $product */
            $productId = NuxtData::value($data, $product, 'productId');

            if (is_int($productId) || is_string($productId)) {
                $products[(string) $productId] = new AnnouncedOffer(
                    startsAt: DutchDate::startOfDay(NuxtData::value($data, $product, 'startDate')) ?? $offer->startsAt,
                    endsAt: DutchDate::endOfDay(NuxtData::value($data, $product, 'endDate')) ?? $offer->endsAt,
                    offerPrice: PriceNormalizer::fromMixed(NuxtData::value($data, $product, 'offerPrice')),
                    text: $offer->text,
                );
            }
        }

        return $products;
    }

    /**
     * Whether the payload is an offers page at all: Dirk lists its offers
     * under `upcomingOffers` or `currentOffers`, DekaMarkt under `offers`. A
     * maintenance page can carry a Nuxt payload too, and must not read as
     * "nothing announced".
     *
     * @param  list<mixed>  $data
     */
    public static function listsOffers(array $data): bool
    {
        return array_any($data, fn (mixed $record): bool => is_array($record)
            && array_intersect(['upcomingOffers', 'currentOffers', 'offers'], array_keys($record)) !== []);
    }

    /** `2026-11-03T00:00:00.000Z` names 3 November, not 01:00 that day in Amsterdam. */
    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $match) === 1 ? $match[0] : null;
    }

    public static function same(?AnnouncedOffer $a, ?AnnouncedOffer $b): bool
    {
        return $a !== null && $b !== null && $a->toArray() === $b->toArray();
    }
}
