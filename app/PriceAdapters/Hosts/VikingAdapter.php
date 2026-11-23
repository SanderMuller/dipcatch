<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\JsonLdEntities;
use App\PriceAdapters\JsonLdOfferPrice;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use App\Support\Numeric;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Host-specific adapter for vikingdirect.nl.
 *
 * Viking sells to businesses first, and its page differs from the price a
 * shopper pays for one item in two ways (Twix Minis, 2026-11-23):
 *
 * - The offer's own `price` is the cheapest quantity tier, "6+ Pakken" at
 *   €5,99. One pack costs €7,49, and only a tier spec says so.
 * - Every price is without VAT. The JSON-LD says `valueAddedTaxIncluded:
 *   false` but not the rate, so the rate is read from the page: it prints the
 *   headline price beside the same price with VAT, "€ 5,99" and "€ 6,53 Incl.
 *   btw".
 *
 * The page carries both "Excl. btw" and "Incl. btw" (a price toggle), so the
 * generic VAT check cannot call it. A page whose VAT rate cannot be read
 * fails rather than store a price without VAT.
 */
final readonly class VikingAdapter extends HostAdapter
{
    /** The VAT rates the Netherlands charges, as a factor on the price without VAT. */
    private const array VAT_FACTORS = ['1.09', '1.21'];

    public function key(): string
    {
        return 'viking';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'vikingdirect.nl' => 'EUR',
        ];
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $result = parent::extract($url, $html, $context);
        $snapshot = $result->snapshot;

        if (! $result->isSuccess() || ! $snapshot instanceof ShopSnapshot) {
            return $result;
        }

        $price = self::singleItemPrice($snapshot, $html);

        return $price === null
            ? ExtractionResult::failed('viking_vat_unreadable')
            : ExtractionResult::success($snapshot->withPrice($price));
    }

    /** Only the structured data states the tiers and the VAT; there is no markup to fall back on. */
    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        return null;
    }

    /**
     * The price of one item with VAT. Null when the offer states its prices
     * without VAT and the page states no rate that explains its own two
     * headline prices.
     */
    private static function singleItemPrice(ShopSnapshot $snapshot, string $html): ?string
    {
        $offer = JsonLdEntities::pickOfferFromProduct($snapshot->raw['offer'] ?? null) ?? [];
        $tier = JsonLdOfferPrice::singleItemTier($offer);
        $price = PriceNormalizer::fromMixed($tier['price'] ?? null) ?? $snapshot->price;

        if (! self::excludesVat($tier ?? $offer, $offer)) {
            return $price;
        }

        $factor = self::vatFactor($html);

        return $factor === null ? null : number_format(round((float) bcmul(Numeric::str($price), $factor, 4), 2), 2, '.', '');
    }

    /**
     * Whether the priced spec, or failing that any spec or the offer itself,
     * says the price leaves VAT out. An offer with no tier for one item still
     * states it on the tiers it has.
     *
     * @param  array<string, mixed>  $priced
     * @param  array<string, mixed>  $offer
     */
    private static function excludesVat(array $priced, array $offer): bool
    {
        $specs = $offer['priceSpecification'] ?? null;
        $stating = is_array($specs) && array_is_list($specs)
            ? array_find($specs, static fn (mixed $spec): bool => is_array($spec) && array_key_exists('valueAddedTaxIncluded', $spec))
            : null;

        return ($priced['valueAddedTaxIncluded'] ?? $stating['valueAddedTaxIncluded'] ?? $offer['valueAddedTaxIncluded'] ?? null) === false;
    }

    /**
     * The VAT factor that turns the headline price into the price the page
     * prints beside it with VAT, to the cent. Null when the page prints no
     * such pair, or no Dutch rate explains it.
     *
     * @return numeric-string|null
     */
    private static function vatFactor(string $html): ?string
    {
        $crawler = new Crawler($html);
        $alternative = $crawler->filter('[id^="productPriceAlternative_"]');
        $without = self::amount($crawler->filter('[id^="productPrice_"]'));
        $with = $alternative->count() > 0 && stripos($alternative->first()->text(), 'incl') !== false ? self::amount($alternative) : null;

        if ($without === null || $with === null) {
            return null;
        }

        return array_find(self::VAT_FACTORS, static fn (string $factor): bool => bccomp(
            number_format(round((float) bcmul($without, $factor, 4), 2), 2, '.', ''),
            $with,
            2,
        ) === 0);
    }

    /** @return numeric-string|null */
    private static function amount(Crawler $node): ?string
    {
        if ($node->count() === 0 || preg_match('/\d[\d.,]*/', $node->first()->text(), $match) !== 1) {
            return null;
        }

        return PriceNormalizer::fromMixed($match[0]);
    }
}
