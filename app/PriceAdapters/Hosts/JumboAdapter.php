<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\PageMarkup;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Offers\JumboUpcomingPromotion;
use App\Support\PackSize;
use Carbon\CarbonImmutable;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Host-specific adapter for jumbo.com. Jumbo ships JSON-LD (an
 * AggregateOffer whose high/low equal the shown price), so the happy path
 * delegates there. The CSS fallback reads the server-rendered price
 * component: `[data-testid="product-price"]` contains a screenreader div
 * ("Prijs: € 7,59") plus `.whole`/`.fractional` spans.
 */
final readonly class JumboAdapter extends HostAdapter
{
    public function __construct(private ?JumboUpcomingPromotion $announced = null) {}

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $result = parent::extract($url, $html, $context);

        if (! $result->isSuccess() || $result->snapshot === null) {
            return $result;
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        $product = $crawler->filter('.product-panel-info')->first();

        if ($product->count() === 0 || $product->filter('[data-testid="product-price"]')->count() === 0) {
            return $result;
        }

        $promotion = $product->filter('[data-testautomation="pdp-promotion"]')->first();
        $labelNode = $promotion->filter('[data-testid="promotion-tag"]')->first();
        $label = $labelNode->count() > 0 ? trim($labelNode->text('')) : null;
        $window = $promotion->count() > 0 ? self::promotionWindow($promotion, $label) : null;
        $hasDateText = $promotion->filter('[data-testid="product-communication"]')->count() > 0;
        $snapshot = $result->snapshot;

        if ($snapshot->inStock === null && self::hasEnabledAddToCartButton($product)) {
            $snapshot = $snapshot->withStock(inStock: true, stockSignal: 'jumbo:add-to-cart');
        }

        if ($hasDateText && $window === null) {
            $snapshot = $snapshot->withRaw([...$snapshot->raw, 'bundle_diagnostic' => 'invalid_promotion_window']);
        }

        $offer = is_string($label) && (! $hasDateText || $window !== null)
            ? BundleOffer::fromLabel($label, $snapshot->price)
            : null;

        // The promotion block is authoritative, but it states only the
        // periods it runs itself. When it names none, the period the JSON-LD
        // offer stated stands, unless it is not running and Jumbo has
        // announced next week's promotion.
        $snapshot = self::withShelfPackSize($snapshot, $product)
            ->withPromotionWindow($window ?? $snapshot->promotionWindow)
            ->withBundleOffer($offer);

        if ($promotion->count() > 0 || $snapshot->promotionWindow?->isRunning() === true) {
            return ExtractionResult::success($snapshot);
        }

        return ExtractionResult::success($this->announced?->applyTo($snapshot, $url) ?? $snapshot);
    }

    /**
     * The pack size the shelf price states, checked against the price per
     * kilo or litre beside it.
     *
     * The JSON-LD states no size, and the title can mislead: "8 Stuks 200 g"
     * read as eight packs of 200 g, while the page sells 200 g for € 1,95 at
     * € 9,75 per kilo (verified 2026-10-17). The subtitle under the h1 states
     * the size on most pages, and the title on the rest; the one the unit
     * price confirms is the whole pack. A page whose unit price is per piece
     * or per wash, or that agrees with neither, keeps the snapshot as it is.
     */
    private static function withShelfPackSize(ShopSnapshot $snapshot, Crawler $product): ShopSnapshot
    {
        $implied = self::sizeFromUnitPrice($product);

        if (! $implied instanceof PackSize) {
            return $snapshot;
        }

        $candidates = [PageMarkup::element($product, '[data-testid="product-subtitle"]'), $snapshot->title];

        foreach ($candidates as $candidate) {
            $stated = PackSize::parse($candidate);

            // The unit price is rounded to cents: 1,19 at 7,73 per kilo is
            // 153.9 g for a 154 g pack.
            if ($stated instanceof PackSize && $stated->unit === $implied->unit && abs($stated->quantity - $implied->quantity) <= $implied->quantity * 0.03) {
                return $snapshot->withPackSize(rtrim(rtrim(number_format($stated->quantity, 2, '.', ''), '0'), '.') . ' ' . $stated->unit, wholePack: true);
            }
        }

        return $snapshot;
    }

    /** "Prijs: € 1,95" over "€ 9,75 per kilo" is 200 g; null for a unit that is not a weight or a volume. */
    private static function sizeFromUnitPrice(Crawler $product): ?PackSize
    {
        $component = $product->filter('[data-testid="product-price"]')->first();
        $perUnit = trim($component->filter('.price-per-unit .screenreader-only')->first()->text(''));

        if (preg_match('/^(.+?)\s+per\s+(kilo|liter)$/iu', $perUnit, $matches) !== 1) {
            return null;
        }

        $price = self::priceFromComponent($product);
        $rate = PriceNormalizer::fromMixed($matches[1]);

        if ($price === null || $rate === null || (float) $rate <= 0.0) {
            return null;
        }

        return PackSize::of((float) $price / (float) $rate * 1000, strtolower($matches[2]) === 'kilo' ? 'g' : 'ml');
    }

    public function key(): string
    {
        return 'jumbo';
    }

    /**
     * @return array<string, string>
     */
    protected function hosts(): array
    {
        return [
            'jumbo.com' => 'EUR',
        ];
    }

    protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        $price = self::priceFromComponent($crawler);
        if ($price === null) {
            return null;
        }

        return new ShopSnapshot(
            title: PageMarkup::meta($crawler, 'meta[property="og:title"]')
                ?? PageMarkup::element($crawler, 'h1')
                ?? 'Jumbo product',
            imageUrl: PageMarkup::ogImage($crawler),
            price: $price,
            currency: $currency,
            inStock: true,
            raw: ['source' => 'jumbo-css'],
        );
    }

    private static function priceFromComponent(Crawler $crawler): ?string
    {
        $component = $crawler->filter('[data-testid="product-price"]')->first();
        if ($component->count() === 0) {
            return null;
        }

        $screenreader = $component->filter('.current-price .screenreader-only')->first();
        if ($screenreader->count() > 0) {
            $price = PriceNormalizer::fromMixed(trim($screenreader->text('')));
            if ($price !== null) {
                return $price;
            }
        }

        // Screenreader div missing or unparseable — rebuild from the visual spans.
        $whole = $component->filter('.current-price .whole')->first();
        $fractional = $component->filter('.current-price .fractional')->first();
        if ($whole->count() === 0 || $fractional->count() === 0) {
            return null;
        }

        return PriceNormalizer::fromMixed(trim($whole->text('')) . ',' . trim($fractional->text('')));
    }

    private static function promotionWindow(Crawler $promotion, ?string $label): ?PromotionWindow
    {
        $communication = $promotion->filter('[data-testid="product-communication"]')->first();

        if ($communication->count() === 0) {
            return null;
        }

        $heading = $communication->filter('[data-testid="jum-heading"], h6')->first();
        $text = mb_strtolower(trim($heading->count() > 0 ? $heading->text('') : $communication->text('')));

        if (preg_match('/geldig van\s+\p{L}+\s+(\d{1,2})(?:\s+(\p{L}+))?\s+t\/m\s+\p{L}+\s+(\d{1,2})\s+(\p{L}+)/u', $text, $matches) !== 1) {
            return null;
        }

        $months = [
            'jan' => 1, 'feb' => 2, 'mrt' => 3, 'apr' => 4, 'mei' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
        ];
        $endMonth = $months[$matches[4]] ?? null;
        $startMonth = $months[$matches[2] ?: $matches[4]] ?? null;

        if ($startMonth === null || $endMonth === null) {
            return null;
        }

        try {
            $now = CarbonImmutable::now('Europe/Amsterdam');
            $end = CarbonImmutable::createSafe($now->year, $endMonth, (int) $matches[3], 23, 59, 59, 'Europe/Amsterdam');

            if ($end === null) {
                return null;
            }

            if ($end->lessThan($now->subMonths(6))) {
                $end = $end->addYear();
            } elseif ($end->greaterThan($now->addMonths(6))) {
                $end = $end->subYear();
            }

            $startYear = $endMonth < $startMonth ? $end->year - 1 : $end->year;

            $start = CarbonImmutable::createSafe($startYear, $startMonth, (int) $matches[1], 0, 0, 0, 'Europe/Amsterdam');

            if ($start === null) {
                return null;
            }

            return PromotionWindow::make(
                endsAt: $end,
                startsAt: $start,
                label: $label,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function hasEnabledAddToCartButton(Crawler $product): bool
    {
        $button = $product->filter('button[aria-label^="Toevoegen aan mandje"]')->first();

        return $button->count() > 0
            && $button->attr('disabled') === null
            && $button->attr('aria-disabled') !== 'true';
    }
}
