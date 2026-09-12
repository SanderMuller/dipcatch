<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\NuxtData;
use Carbon\CarbonImmutable;

/**
 * Host-specific adapter for lidl.nl. Price, title, and image come from the
 * page's JSON-LD. The pack size lives only in the Nuxt `__NUXT_DATA__`
 * payload: the product record's `price` points at a price record whose
 * `packaging.text` holds the size ("370 g"), so this adapter augments the
 * JSON-LD snapshot with it.
 *
 * The offer period lives further away still — in the stock-availability
 * record's badge, which states it as "Alleen in de winkel 31/08 - 06/09"
 * with `validFrom` / `validUntil` beside it (verified 2026-09-03). Lidl's
 * JSON-LD carries no `priceValidUntil`, so without reading that badge a
 * weekly action reads as a permanent price.
 */
final readonly class LidlAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'lidl';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matches($url, 'lidl.nl')) {
            return ExtractionResult::skip();
        }

        $result = new JsonLdAdapter()->extract($url, $html, $context);
        if (! $result->isSuccess()) {
            return $result->isSkip() ? ExtractionResult::failed('lidl_extraction_failed') : $result;
        }

        $snapshot = $result->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        $data = NuxtData::decode($html);
        $packaging = self::packagingFromNuxtPayload($html, HostUrl::lastSegmentDigits($url, 'p'));

        return ExtractionResult::success($snapshot->with(
            packSize: $packaging ?? $snapshot->packSize,
            packSizeAuthoritative: $packaging !== null ? true : null,
            promotionWindow: $data === null ? null : self::promotionWindow($data),
            // The payload always carries the availability record; an offer
            // that ended simply stops stating a period.
            promotionWindowAuthoritative: $data !== null,
        ));
    }

    /**
     * The offer period, from the stock-availability badge that states it.
     *
     * A page can carry more than one badge; they agree in every page
     * sampled, and when they do not there is no basis to pick one, so no
     * period is reported.
     *
     * @param  list<mixed>  $data
     */
    private static function promotionWindow(array $data): ?PromotionWindow
    {
        $windows = [];

        foreach ($data as $element) {
            if (! is_array($element) || ! isset($element['validFrom'], $element['validUntil'])) {
                continue;
            }

            $from = is_int($element['validFrom']) ? ($data[$element['validFrom']] ?? null) : null;
            $until = is_int($element['validUntil']) ? ($data[$element['validUntil']] ?? null) : null;

            if (is_int($from) && is_int($until)) {
                $windows[$from . '-' . $until] = [$from, $until];
            }
        }

        if (count($windows) !== 1) {
            return null;
        }

        [$from, $until] = array_values($windows)[0];

        return PromotionWindow::make(
            endsAt: CarbonImmutable::createFromTimestampUTC($until),
            startsAt: CarbonImmutable::createFromTimestampUTC($from),
        );
    }

    /**
     * The product record is the dict carrying both `productId` and `price`;
     * `productId` disambiguates when related products ride along. The chain
     * is product → price record → packaging record → `text`.
     */
    private static function packagingFromNuxtPayload(string $html, ?string $productId): ?string
    {
        $data = NuxtData::decode($html);
        if ($data === null) {
            return null;
        }

        $deref = static fn (mixed $v): mixed => is_int($v) && isset($data[$v]) ? $data[$v] : null;

        $fallback = null;

        foreach ($data as $element) {
            if (! is_array($element) || ! isset($element['productId'], $element['price'])) {
                continue;
            }

            $price = $deref($element['price']);
            if (! is_array($price)) {
                continue;
            }

            $packagingRecord = $deref($price['packaging'] ?? null);
            $packaging = is_array($packagingRecord) ? $deref($packagingRecord['text'] ?? null) : null;
            if (! is_string($packaging) || $packaging === '') {
                continue;
            }

            $recordId = $deref($element['productId']);
            if ($productId !== null && (is_string($recordId) || is_int($recordId)) && (string) $recordId === $productId) {
                return $packaging;
            }

            $fallback ??= $packaging;
        }

        return $fallback;
    }

    /**
     * Lidl product URLs end in a `p<digits>` segment (`/p/lay-s/p10033095`).
     */
}
