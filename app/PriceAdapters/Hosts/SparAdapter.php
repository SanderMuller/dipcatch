<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\OwnsHosts;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for spar.nl. Price, title, image and GTIN all come
 * from the page's JSON-LD, which the generic adapter already handled — the
 * one thing missing was the pack size, so SPAR shops showed no unit price.
 *
 * The size sits in the offer block's subtitle (`200 Gram`), never in the
 * JSON-LD (verified 2026-09-02).
 *
 * The JSON-LD `priceValidUntil` is no promotion: every product states the
 * same date, on offer or not, and a folder offer never shows on the product
 * page (verified 2026-10-24).
 */
final readonly class SparAdapter implements HostSpecificAdapter, OwnsHosts, ShopAdapter
{
    public function key(): string
    {
        return 'spar';
    }

    public function ownedHosts(): array
    {
        return ['spar.nl'];
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matchesAny($url, $this->ownedHosts())) {
            return ExtractionResult::skip();
        }

        $result = new JsonLdAdapter()->extract($url, $html, $context);

        if (! $result->isSuccess()) {
            return $result->isSkip() ? ExtractionResult::failed('spar_extraction_failed') : $result;
        }

        $snapshot = $result->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        $snapshot = $snapshot->withPromotionWindow(promotionWindow: null);
        $packSize = self::packSize($html);

        return ExtractionResult::success($packSize === null ? $snapshot : $snapshot->withPackSize($packSize));
    }

    /**
     * The offer block's subtitle. Related-product cards render their size in
     * a different class, so the first match belongs to this product.
     */
    private static function packSize(string $html): ?string
    {
        $subtitle = new Crawler($html)->filter('.c-offer__subtitle')->first();

        if ($subtitle->count() === 0) {
            return null;
        }

        $text = trim($subtitle->text(''));

        return $text === '' ? null : $text;
    }
}
