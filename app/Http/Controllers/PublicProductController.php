<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ShopHealth;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Support\BundlePriceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Response;

/**
 * Renders the public product page at GET /p/{slug}. No auth.
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
        /** @var Product $product */
        $product = Product::query()
            ->select(['id', 'title', 'image_url', 'currency', 'cheapest_price', 'share_slug'])
            ->where('share_slug', $slug)
            ->firstOrFail();

        /** @var EloquentCollection<int, Shop> $shops */
        $shops = $product->shops()
            ->select(['id', 'product_id', 'host', 'current_price', 'single_item_price', 'bundle_quantity', 'bundle_total_price', 'promotion_starts_at', 'promotion_ends_at', 'promotion_label', 'current_in_stock', 'currency', 'last_checked_at', 'url', 'pack_quantity', 'pack_unit'])
            ->where('active', true)
            ->where(fn (EloquentBuilder $stock): EloquentBuilder => $stock
                ->where('current_in_stock', true)
                ->orWhereNull('current_in_stock'))
            ->where('health', '!=', ShopHealth::Dead->value)
            ->where('currency', $product->currency)
            ->whereNotNull('current_price')
            ->orderBy('current_price')->oldest()
            ->orderBy('id')
            ->get();

        $chart = $this->chartPayload($product);

        return response()
            ->view('public.product', [
                'product' => $product,
                'shops' => $shops,
                'chart' => $chart,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Build the [{x: ISO timestamp, y: decimal price string}, ...] payload
     * the Chart.js line chart consumes. Reads from ProductCheapestHistory
     * for segments that overlap the last 90 days — a price that has not moved
     * since before the window is still the current price. Each segment
     * contributes two points (started_at, ended_at) so the line steps when
     * the cheapest shop changes; the open segment's right edge is "now".
     *
     * @return list<array{x: string, y: string|null, bundle: ?string}>
     */
    private function chartPayload(Product $product): array
    {
        $cutoff = CarbonImmutable::now()->subDays(90);

        /** @var EloquentCollection<int, ProductCheapestHistory> $segments */
        $segments = ProductCheapestHistory::query()
            ->select(['cheapest_price', 'single_item_price', 'bundle_quantity', 'bundle_total_price', 'started_at', 'ended_at'])
            ->where('product_id', $product->id)
            ->overlapping($cutoff)
            ->inOrder()
            ->get();

        $points = [];
        $now = CarbonImmutable::now();
        foreach ($segments as $segment) {
            $price = $segment->cheapest_price === null ? null : (string) $segment->cheapest_price;
            $ended = $segment->ended_at ?? $now;
            // A segment may start long before the window it is drawn in, so
            // clip its left edge to the cutoff. The heading above the canvas
            // promises 90 days and the time axis has no floor of its own.
            $started = $segment->started_at->max($cutoff);
            $bundle = BundlePriceLabel::forHistory($segment, $product->currency);
            $points[] = ['x' => $started->toIso8601String(), 'y' => $price, 'bundle' => $bundle];
            $points[] = ['x' => $ended->toIso8601String(), 'y' => $price, 'bundle' => $bundle];
        }

        return $points;
    }
}
