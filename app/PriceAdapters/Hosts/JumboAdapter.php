<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopSnapshot;
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
        $raw = $result->snapshot->raw;

        if ($hasDateText && $window === null) {
            $raw['bundle_diagnostic'] = 'invalid_promotion_window';
        }

        $offer = is_string($label) && (! $hasDateText || $window !== null)
            ? BundleOffer::fromLabel($label, $result->snapshot->price)
            : null;

        return ExtractionResult::success($result->snapshot->with(
            promotionWindow: $window,
            promotionWindowAuthoritative: true,
            bundleOffer: $offer,
            bundleOfferAuthoritative: true,
            raw: $raw,
        ));
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

        $titleNode = $crawler->filter('meta[property="og:title"]')->first();
        $title = $titleNode->count() > 0 ? trim((string) $titleNode->attr('content')) : '';
        if ($title === '') {
            $h1 = $crawler->filter('h1')->first();
            $title = $h1->count() > 0 ? trim($h1->text('')) : 'Jumbo product';
        }

        $imageNode = $crawler->filter('meta[property="og:image"]')->first();
        $image = $imageNode->count() > 0 ? $imageNode->attr('content') : null;

        return new ShopSnapshot(
            title: $title,
            imageUrl: $image,
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

        $text = mb_strtolower(trim($communication->text('')));

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
}
