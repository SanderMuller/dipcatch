<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\JsonLdEntities;
use App\PriceAdapters\OwnsHosts;
use App\PriceAdapters\PageMarkup;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\PriceAdapters\StockAvailability;
use App\Services\Checkjebon\LidlShelfPrice;
use App\Support\NuxtData;
use Carbon\CarbonImmutable;
use Symfony\Component\DomCrawler\Crawler;

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
 *
 * A grocery page states no price at all, so its price comes from the
 * checkjebon dataset instead ({@see LidlShelfPrice}), and its title from the
 * Nuxt product record.
 */
final readonly class LidlAdapter implements HostSpecificAdapter, OwnsHosts, ShopAdapter
{
    public function __construct(private LidlShelfPrice $shelfPrices) {}

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

        if ($result->failureReason === 'jsonld_no_price') {
            return $this->fromShelfPrice($url, $html);
        }

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
     * The dataset's shelf price for a page that states none. Title and image
     * come from the page, the pack size from the dataset row or else the
     * page. The dataset holds the regular price, so the page's offer period
     * is not attached: it would mark a regular price as a running deal.
     */
    private function fromShelfPrice(string $url, string $html): ExtractionResult
    {
        $data = NuxtData::decode($html);
        $productId = HostUrl::lastSegmentDigits($url, 'p');
        $record = $data === null || $productId === null
            ? null
            : NuxtData::recordsFor($data, ['productId', 'ians', 'keyfacts'], 'productId', $productId)[0] ?? null;

        if ($data === null || $productId === null || $record === null) {
            return ExtractionResult::failed('lidl_no_price');
        }

        $keyfacts = NuxtData::value($data, $record, 'keyfacts');
        $title = null;

        if (is_array($keyfacts)) {
            /** @var array<string, mixed> $keyfacts */
            $title = NuxtData::value($data, $keyfacts, 'title');
        }

        $ians = NuxtData::value($data, $record, 'ians');
        $ians = is_array($ians)
            ? array_values(array_filter(array_map(fn (mixed $index): mixed => is_int($index) ? ($data[$index] ?? null) : $index, $ians), is_string(...)))
            : [];

        if (! is_string($title) || $title === '') {
            return ExtractionResult::failed('lidl_no_price');
        }

        $row = $this->shelfPrices->rowFor($ians, $title);

        if ($row === null) {
            return ExtractionResult::failed('lidl_no_price');
        }

        $pagePack = self::packagingFromNuxtPayload($data, $productId);

        // A row for another pack of the same product is another price.
        if ($row->size !== null && $pagePack !== null && self::packKey($row->size) !== self::packKey($pagePack)) {
            return ExtractionResult::failed('lidl_no_price');
        }

        $packSize = $row->size ?? $pagePack;
        $crawler = new Crawler($html);
        // The page's offer still says whether the product is sold; null lets
        // the resolver read the page text instead.
        [$inStock, $stockSignal] = StockAvailability::read(self::offerAvailability($crawler));

        return ExtractionResult::success(new ShopSnapshot(
            title: $title,
            imageUrl: PageMarkup::ogImage($crawler),
            price: (string) $row->price,
            currency: 'EUR',
            inStock: $inStock,
            raw: [
                'source' => 'checkjebon',
                'ian' => $row->external_id,
                'refreshed_at' => $row->refreshed_at->toIso8601String(),
            ],
            packSize: $packSize,
            packSizeAuthoritative: $packSize !== null,
            // Authoritative and empty, so a period stored from an earlier
            // priced page is cleared rather than kept on the regular price.
            promotionWindowAuthoritative: true,
            stockSignal: $stockSignal,
        ));
    }

    private static function packKey(string $pack): string
    {
        return (string) preg_replace('/\s+/', '', str_replace(',', '.', mb_strtolower($pack)));
    }

    /**
     * The `availability` of the page's Product offer, which states it even
     * where it states no price.
     */
    private static function offerAvailability(Crawler $crawler): mixed
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $decoded = json_decode($node->textContent, true);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === 'Product') {
                return JsonLdEntities::pickOfferFromProduct($decoded['offers'] ?? null)['availability'] ?? null;
            }
        }

        return null;
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
