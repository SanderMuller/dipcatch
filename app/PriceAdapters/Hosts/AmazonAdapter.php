<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use JsonException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Amazon product pages rarely emit schema.org JSON-LD, so the base class's
 * JSON-LD pass almost always skips and the CSS fallback below carries the
 * load. Multiple price containers are tried because Amazon's PDP layout
 * varies by category and TLD.
 */
final readonly class AmazonAdapter extends HostAdapter
{
    /**
     * Ordered CSS selectors for the buy-box price. Live-price scopes
     * (`.priceToPay`, `[data-a-color="base"]`) come first so we don't pick up
     * the struck-through list price that Amazon renders before the current
     * price inside the same container. Bare-container selectors stay as a
     * last-resort fallback for layouts without those marker classes.
     *
     * @var list<string>
     */
    private const array PRICE_SELECTORS = [
        '#corePriceDisplay_desktop_feature_div .priceToPay .a-offscreen',
        '#corePriceDisplay_desktop_feature_div span[data-a-color="base"] .a-offscreen',
        '#corePrice_feature_div .priceToPay .a-offscreen',
        '#corePrice_feature_div span[data-a-color="base"] .a-offscreen',
        '#apex_desktop .priceToPay .a-offscreen',
        '#apex_desktop span[data-a-color="base"] .a-offscreen',
        '#corePriceDisplay_desktop_feature_div .a-offscreen',
        '#corePrice_feature_div .a-offscreen',
        '#apex_desktop .a-offscreen',
        '#priceblock_ourprice',
        '#priceblock_dealprice',
        '#priceblock_saleprice',
    ];

    public function key(): string
    {
        return 'amazon';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'amazon.com' => 'USD',
            'amazon.co.uk' => 'GBP',
            'amazon.de' => 'EUR',
            'amazon.nl' => 'EUR',
            'amazon.fr' => 'EUR',
            'amazon.es' => 'EUR',
            'amazon.it' => 'EUR',
            'amazon.ie' => 'EUR',
            'amazon.se' => 'SEK',
            'amazon.pl' => 'PLN',
            'amazon.ca' => 'CAD',
            'amazon.com.au' => 'AUD',
            'amazon.co.jp' => 'JPY',
            'amazon.in' => 'INR',
            'amazon.com.mx' => 'MXN',
            'amazon.com.br' => 'BRL',
            'amazon.com.be' => 'EUR',
            'amazon.ae' => 'AED',
            'amazon.sa' => 'SAR',
            'amazon.com.tr' => 'TRY',
            'amazon.sg' => 'SGD',
        ];
    }

    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $price = $this->extractPrice($crawler);
        if ($price === null) {
            return null;
        }

        return new ShopSnapshot(
            title: $this->extractTitle($crawler),
            imageUrl: $this->extractImage($crawler),
            price: $price,
            currency: $currency,
            inStock: $this->extractInStock($crawler),
            raw: ['source' => 'amazon-css'],
        );
    }

    private function extractPrice(Crawler $crawler): ?string
    {
        foreach (self::PRICE_SELECTORS as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                continue;
            }

            $price = PriceNormalizer::fromMixed(trim($node->text('')));
            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    private function extractTitle(Crawler $crawler): string
    {
        $node = $crawler->filter('#productTitle')->first();

        if ($node->count() === 0) {
            return 'Amazon product';
        }

        $text = trim($node->text(''));

        return $text !== '' ? $text : 'Amazon product';
    }

    private function extractImage(Crawler $crawler): ?string
    {
        $landing = $crawler->filter('#landingImage')->first();

        if ($landing->count() > 0) {
            $dynamic = $landing->attr('data-a-dynamic-image');
            $fromDynamic = is_string($dynamic) && $dynamic !== ''
                ? self::firstUrlFromDynamicImage($dynamic)
                : null;
            if ($fromDynamic !== null) {
                return $fromDynamic;
            }

            $src = $landing->attr('src');
            if (is_string($src) && $src !== '') {
                return $src;
            }
        }

        $og = $crawler->filter('meta[property="og:image"]')->first();
        if ($og->count() === 0) {
            return null;
        }

        $content = $og->attr('content');

        return is_string($content) && $content !== '' ? $content : null;
    }

    private function extractInStock(Crawler $crawler): bool
    {
        $availability = $crawler->filter('#availability')->first();
        if ($availability->count() === 0) {
            return true;
        }

        // Amazon paints unavailable messages with `.a-color-price` or
        // `.a-color-error` (red) across every locale, so reading the class
        // sidesteps the localized phrase list. Text matching stays as a
        // fallback for layouts without the color class.
        if ($availability->filter('.a-color-price, .a-color-error')->count() > 0) {
            return false;
        }

        $text = strtolower(trim($availability->text('')));

        return array_all(['currently unavailable', 'niet beschikbaar', 'derzeit nicht verfügbar', 'non disponible', 'no disponible', 'attualmente non disponibile'], fn (string $needle): bool => ! str_contains($text, $needle));
    }

    private static function firstUrlFromDynamicImage(string $json): ?string
    {
        // data-a-dynamic-image is HTML-escaped JSON of { "url": [w, h], ... }.
        $decodedJson = html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        try {
            $decoded = json_decode($decodedJson, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $first = array_key_first($decoded);

        return is_string($first) && $first !== '' ? $first : null;
    }
}
