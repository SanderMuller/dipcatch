<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\DutchDate;
use App\Support\NuxtData;

/**
 * Host-specific adapter for dirk.nl. Price and title come from the page's
 * JSON-LD (which carries the live promo price under a capitalized `Price`
 * key the JsonLdAdapter already accepts). The pack size lives only in the
 * Nuxt `__NUXT_DATA__` payload — a flat array where object values are
 * indices into the same array — as the product record's `packaging` field
 * ("150 g"), so this adapter augments the JSON-LD snapshot with it.
 */
final readonly class DirkAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'dirk';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matches($url, 'dirk.nl')) {
            return ExtractionResult::skip();
        }

        $result = new JsonLdAdapter()->extract($url, $html, $context);
        if (! $result->isSuccess()) {
            return $result->isSkip() ? ExtractionResult::failed('dirk_extraction_failed') : $result;
        }

        $snapshot = $result->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        $productId = HostUrl::lastNumericSegment($url);
        $packaging = self::packagingFromNuxtPayload($html, $productId);

        return ExtractionResult::success($snapshot->with(
            packSize: $packaging ?? $snapshot->packSize,
            packSizeAuthoritative: $packaging !== null ? true : null,
            promotionWindow: self::promotionWindow($html, $productId, $snapshot->price),
            promotionWindowAuthoritative: true,
        ));
    }

    /**
     * The product record is the dict carrying both `headerText` and
     * `packaging`; `productId` disambiguates when related products ride
     * along.
     */
    /**
     * The offer period behind the price, when the payload holds a price
     * record for this product whose offer price is the price the JSON-LD
     * reported. A record that prices something else describes a different
     * offer, and its dates would be attached to a price they do not cover.
     */
    private static function promotionWindow(string $html, ?string $productId, string $price): ?PromotionWindow
    {
        if ($productId === null) {
            return null;
        }

        $data = NuxtData::decode($html);

        if ($data === null) {
            return null;
        }

        foreach (NuxtData::recordsFor($data, ['productId', 'offerPrice'], 'productId', $productId) as $record) {
            $offer = PriceNormalizer::fromMixed(NuxtData::value($data, $record, 'offerPrice'));

            if ($offer === null || $offer !== $price) {
                continue;
            }

            $window = PromotionWindow::make(
                endsAt: DutchDate::endOfDay(NuxtData::value($data, $record, 'endDate')),
                startsAt: DutchDate::startOfDay(NuxtData::value($data, $record, 'startDate')),
            );

            if ($window !== null) {
                return $window;
            }
        }

        return null;
    }

    private static function packagingFromNuxtPayload(string $html, ?string $productId): ?string
    {
        $data = NuxtData::decode($html);
        if ($data === null) {
            return null;
        }

        $deref = static fn (mixed $v): mixed => is_int($v) && isset($data[$v]) ? $data[$v] : null;

        $fallback = null;

        foreach ($data as $element) {
            if (! is_array($element) || ! isset($element['packaging'], $element['headerText'])) {
                continue;
            }

            $packaging = $deref($element['packaging']);
            if (! is_string($packaging) || $packaging === '') {
                continue;
            }

            $recordId = $deref($element['productId'] ?? null);
            if ($productId !== null && (is_string($recordId) || is_int($recordId)) && (string) $recordId === $productId) {
                return $packaging;
            }

            $fallback ??= $packaging;
        }

        return $fallback;
    }
}
