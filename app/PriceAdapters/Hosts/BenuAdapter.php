<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\ShopSnapshot;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for benushop.nl.
 *
 * The schema.org Offer states the single-item price. A running deal shows
 * only as a label over the product photo: an image whose alt text is
 * "Actielabel 1 plus 1 gratis" (Roter Vitamine C, 2026-10-02), so the deal is
 * read from there.
 */
final readonly class BenuAdapter extends HostAdapter
{
    private const string LABEL_PREFIX = 'Actielabel';

    public function key(): string
    {
        return 'benu';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'benushop.nl' => 'EUR',
        ];
    }

    /** Only the structured data states the price; there is no markup to fall back on. */
    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        return null;
    }

    /**
     * The page's own label is authoritative: no label, or one no offer can be
     * read from, clears an offer read before.
     */
    protected function refine(ShopSnapshot $snapshot, string $url, string $html, ?AdapterContext $context): ShopSnapshot
    {
        $label = self::dealLabel($html);

        return $snapshot->withBundleOffer($label === null ? null : BundleOffer::fromLabel($label, $snapshot->price));
    }

    /** "Actielabel 1 plus 1 gratis" → "1+1 gratis"; null without a label on the product photo. */
    public static function dealLabel(string $html): ?string
    {
        $crawler = new Crawler($html);
        $image = $crawler->filter('#artikellayover.productinfo-layover img.productinfo-layover-image')->first();
        $alt = $image->count() > 0 ? trim((string) $image->attr('alt')) : '';

        if (! str_starts_with($alt, self::LABEL_PREFIX)) {
            return null;
        }

        $label = trim(substr($alt, strlen(self::LABEL_PREFIX)));

        return $label === '' ? null : (string) preg_replace('/\s+plus\s+/u', '+', $label);
    }
}
