<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ShopHealth;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Support\BundlePriceLabel;
use App\Support\HeadlinePrice;
use App\Support\ProductMarkdown;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Response;

/**
 * Renders the public product page at GET /p/{slug}, and its markdown copy at
 * GET /p/{slug}.md. No auth.
 *
 * Lookup is independent of `ProductResource::getEloquentQuery()`
 * (which scopes to auth()->id()) and of `ProductPolicy::view()` (which only
 * allows the owner). Going through either would 403 / return empty for guests.
 *
 * Column projection is an explicit allowlist: `Product` carries private fields
 * (drop_threshold_*, last_notified_*) and `Shop` carries even more
 * (notes, price_selector, title_selector, image_selector). Selecting only
 * the columns the view needs prevents accidental leakage.
 */
final class PublicProductController extends Controller
{
    public function __invoke(string $slug): View|Response
    {
        [$product, $shops, $headline] = $this->load($slug);

        return response()
            ->view('public.product', [
                'product' => $product,
                'shops' => $shops,
                'chart' => $this->chartPayload($product, $headline->unit),
                'headline' => $headline,
                'packs' => $headline->packs,
                // The two answers the app gives, on the app's own rules: the
                // per-unit winner, and the smallest outlay.
                'bestValueShopId' => $headline->isPerUnit() ? $headline->shop?->id : null,
                'lowestShopId' => $headline->lowestShop->id ?? $headline->shop?->id,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * GET /p/{slug}.md: the same page as markdown, from the same rows. The
     * allowlist below is what keeps the owner's private fields out of it.
     */
    public function markdown(string $slug): Response
    {
        [$product, $shops, $headline] = $this->load($slug);

        return response(ProductMarkdown::shared($product, $shops, $headline))
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The shared product, the shops a guest may see, cheapest first, and the
     * figure the page leads with.
     *
     * The figure is resolved on the owner page's rules: an out-of-stock shop
     * still votes on what the product is measured in, and a trade-only or
     * ex-VAT price wins neither answer. So the query loads those shops too,
     * and the listed rows are filtered after.
     *
     * @return array{0: Product, 1: EloquentCollection<int, Shop>, 2: HeadlinePrice}
     */
    private function load(string $slug): array
    {
        /** @var Product $product */
        $product = Product::query()
            ->select(['id', 'title', 'image_url', 'currency', 'cheapest_price', 'share_slug'])
            ->where('share_slug', $slug)
            ->firstOrFail();

        /** @var EloquentCollection<int, Shop> $tracked */
        $tracked = $product->shops()
            ->select(['id', 'product_id', 'host', 'current_price', 'single_item_price', 'bundle_quantity', 'bundle_total_price', 'promotion_starts_at', 'promotion_ends_at', 'promotion_label', 'current_in_stock', 'currency', 'last_checked_at', 'last_success_at', 'consecutive_failures', 'url', 'pack_quantity', 'pack_unit', 'created_at', 'active', 'health', 'kind', 'consumer_price_issue'])
            ->where('active', true)
            ->where('health', '!=', ShopHealth::Dead->value)
            ->where('currency', $product->currency)
            ->whereNotNull('current_price')
            ->orderBy('current_price')->oldest()
            ->orderBy('id')
            ->get();

        $product->setRelation('shops', $tracked);
        $headline = HeadlinePrice::of($product);

        // Cheapest per unit first, as the owner page lists them; the pack price
        // orders the rest, and every row of a product without a unit.
        $packs = $headline->packs;
        $shops = $tracked
            ->filter(fn (Shop $shop): bool => $shop->current_in_stock !== false)
            ->sortBy(fn (Shop $shop): array => [
                $packs->unitPriceValueOf($shop) === null ? 1 : 0,
                $packs->unitPriceValueOf($shop) ?? (float) $shop->current_price,
            ])
            ->values();

        return [$product, $shops, $headline];
    }

    /**
     * Build the [{x: ISO timestamp, y: decimal price string}, ...] payload
     * the Chart.js line chart consumes. Reads from ProductCheapestHistory
     * for segments that overlap the last 90 days — a price that has not moved
     * since before the window is still the current price. Each segment
     * contributes two points (started_at, ended_at) so the line steps when
     * the cheapest shop changes; the open segment's right edge is "now".
     *
     * Per unit while the page leads per unit: the best value each segment
     * recorded, in the unit the page compares in. A segment kept in another
     * unit, or with no size, is a gap. A history with no point in that unit
     * falls back to the pack price, so the page never shows an empty chart.
     *
     * @return array{unit: ?string, points: list<array{x: string, y: string|null, bundle: ?string}>}
     */
    private function chartPayload(Product $product, ?string $unit): array
    {
        $cutoff = CarbonImmutable::now()->subDays(90);

        /** @var EloquentCollection<int, ProductCheapestHistory> $segments */
        $segments = ProductCheapestHistory::query()
            ->select(['cheapest_shop_id', 'best_value_shop_id', 'cheapest_price', 'best_value_price', 'pack_quantity', 'pack_unit', 'single_item_price', 'bundle_quantity', 'bundle_total_price', 'started_at', 'ended_at'])
            ->where('product_id', $product->id)
            ->overlapping($cutoff)
            ->inOrder()
            ->get();

        if ($unit !== null && self::mostCommonUnit($segments) === $unit) {
            return [
                'unit' => $unit,
                'points' => $this->points($product, $segments, $cutoff, fn (ProductCheapestHistory $segment): ?string => $segment->packSize()?->unit === $unit ? $segment->unitPrice() : null, perUnit: true),
            ];
        }

        return [
            'unit' => null,
            'points' => $this->points($product, $segments, $cutoff, fn (ProductCheapestHistory $segment): ?string => $segment->cheapest_price === null ? null : (string) $segment->cheapest_price, perUnit: false),
        ];
    }

    /**
     * The unit most segments were measured in, as the owner page's chart picks
     * it. A long history in kilos with one segment in pieces stays a kilo
     * history, and plotting it per piece would be almost all gaps.
     *
     * @param  EloquentCollection<int, ProductCheapestHistory>  $segments
     */
    private static function mostCommonUnit(EloquentCollection $segments): ?string
    {
        $counts = $segments->countBy(fn (ProductCheapestHistory $segment): string => $segment->packSize()->unit ?? '')->forget('')->sortDesc();
        $unit = $counts->keys()->first();

        return is_string($unit) ? $unit : null;
    }

    /**
     * @param  EloquentCollection<int, ProductCheapestHistory>  $segments
     * @param  Closure(ProductCheapestHistory): ?string  $valueOf
     * @return list<array{x: string, y: string|null, bundle: ?string}>
     */
    private function points(Product $product, EloquentCollection $segments, CarbonImmutable $cutoff, Closure $valueOf, bool $perUnit): array
    {
        $points = [];
        $now = CarbonImmutable::now();
        foreach ($segments as $segment) {
            $price = $valueOf($segment);
            $ended = $segment->ended_at ?? $now;
            // A segment may start long before the window it is drawn in, so
            // clip its left edge to the cutoff. The heading above the canvas
            // promises 90 days and the time axis has no floor of its own.
            $started = $segment->started_at->max($cutoff);
            // The stored deal belongs to the lowest-price shop. A per-unit point
            // is the best value's, so it carries the deal only when one shop is both.
            $bundle = $perUnit && $segment->best_value_shop_id !== null && $segment->best_value_shop_id !== $segment->cheapest_shop_id
                ? null
                : BundlePriceLabel::forHistory($segment, $product->currency);
            $points[] = ['x' => $started->toIso8601String(), 'y' => $price, 'bundle' => $bundle];
            $points[] = ['x' => $ended->toIso8601String(), 'y' => $price, 'bundle' => $bundle];
        }

        return $points;
    }
}
