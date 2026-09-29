<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\OwnsHosts;
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
final readonly class LidlAdapter implements HostSpecificAdapter, OwnsHosts, ShopAdapter
{
    public function key(): string
    {
        return 'lidl';
    }

    public function ownedHosts(): array
    {
        return ['lidl.nl'];
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matchesAny($url, $this->ownedHosts())) {
            return ExtractionResult::skip();
        }

        $result = new JsonLdAdapter()->extract($url, $html, $context);
        if (! $result->isSuccess()) {
            return $result->isSkip() ? ExtractionResult::failed('lidl_extraction_failed') : $result;
        }

        $snapshot = $result->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        $data = NuxtData::decode($html);

        // Lidl's JSON-LD states no `priceValidUntil`, so the blanket
        // authority it claims covers nothing. Withdraw it when the payload
        // that does carry the period is absent, or a null window clears a
        // promotion an earlier check read from that payload.
        if ($data === null) {
            return ExtractionResult::success($snapshot->withoutPromotionWindowAuthority());
        }

        $productId = HostUrl::lastSegmentDigits($url, 'p');
        $packaging = $productId === null ? null : self::packagingFromNuxtPayload($data, $productId);

        if ($packaging !== null) {
            $snapshot = $snapshot->withPackSize($packaging);
        }

        // The payload always carries the availability record; an offer
        // that ended simply stops stating a period.
        return ExtractionResult::success($snapshot->withPromotionWindow(self::promotionWindow($data)));
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

        [$from, $until] = array_first($windows);

        return PromotionWindow::make(
            endsAt: CarbonImmutable::createFromTimestampUTC($until),
            startsAt: CarbonImmutable::createFromTimestampUTC($from),
        );
    }

    /**
     * The product record is the dict carrying both `productId` and `price`,
     * and the chain from there is price record → packaging record → `text`.
     * A page lists related products in that same shape, so the id is what
     * makes a record this product's — a payload with no record under this id
     * states no size, rather than lending a neighbour's.
     *
     * @param  list<mixed>  $data
     */
    private static function packagingFromNuxtPayload(array $data, string $productId): ?string
    {
        foreach (NuxtData::recordsFor($data, ['productId', 'price'], 'productId', $productId) as $record) {
            $price = NuxtData::value($data, $record, 'price');

            if (! is_array($price)) {
                continue;
            }

            /** @var array<string, mixed> $price */
            $packagingRecord = NuxtData::value($data, $price, 'packaging');

            if (! is_array($packagingRecord)) {
                continue;
            }

            /** @var array<string, mixed> $packagingRecord */
            $packaging = NuxtData::value($data, $packagingRecord, 'text');

            if (is_string($packaging) && $packaging !== '') {
                return $packaging;
            }
        }

        return null;
    }

    /**
     * Lidl product URLs end in a `p<digits>` segment (`/p/lay-s/p10033095`).
     */
}
