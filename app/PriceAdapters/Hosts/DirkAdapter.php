<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\OwnsHosts;
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
final readonly class DirkAdapter implements HostSpecificAdapter, OwnsHosts, ShopAdapter
{
    public function key(): string
    {
        return 'dirk';
    }

    public function ownedHosts(): array
    {
        return ['dirk.nl'];
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matchesAny($url, $this->ownedHosts())) {
            return ExtractionResult::skip();
        }

        $result = new JsonLdAdapter()->extract($url, $html, $context);
        if (! $result->isSuccess()) {
            return $result->isSkip() ? ExtractionResult::failed('dirk_extraction_failed') : $result;
        }

        $snapshot = $result->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        $productId = HostUrl::lastNumericSegment($url);
        $data = NuxtData::decode($html);

        // The payload carries related products in the same shape as this one,
        // so without the id from the URL there is no way to tell which records
        // are ours. Dirk then reads nothing and claims nothing: the JSON-LD
        // offer's period stands, and the pack size falls to the title parse
        // that every adapter stating no size already uses.
        if ($data === null || $productId === null) {
            return ExtractionResult::success($snapshot);
        }

        $packaging = self::packagingFromNuxtPayload($data, $productId);

        if ($packaging !== null) {
            $snapshot = $snapshot->withPackSize($packaging);
        }

        // The payload is the only promotion source this adapter reads, so one
        // that states no period for this product ends the promotion.
        return ExtractionResult::success(
            $snapshot->withPromotionWindow(self::promotionWindow($data, $productId, $snapshot->price)),
        );
    }

    /**
     * The offer period behind the price, when the payload holds a price
     * record for this product whose offer price is the price the JSON-LD
     * reported. A record that prices something else describes a different
     * offer, and its dates would be attached to a price they do not cover.
     *
     * @param  list<mixed>  $data
     */
    private static function promotionWindow(array $data, string $productId, string $price): ?PromotionWindow
    {
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

    /**
     * The product record is the dict carrying both `headerText` and
     * `packaging`. A page lists related products in that same shape, so the
     * id is what makes a record this product's — a payload with no record
     * under this id states no size, rather than lending a neighbour's.
     *
     * @param  list<mixed>  $data
     */
    private static function packagingFromNuxtPayload(array $data, string $productId): ?string
    {
        foreach (NuxtData::recordsFor($data, ['packaging', 'headerText'], 'productId', $productId) as $record) {
            $packaging = NuxtData::value($data, $record, 'packaging');

            if (is_string($packaging) && $packaging !== '') {
                return $packaging;
            }
        }

        return null;
    }
}
