<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ShopHealth;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;

/**
 * Renders the public product page at GET /p/{slug}. No auth.
 *
 * Lookup is independent of {@see ProductResource::getEloquentQuery()}
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

        /** @var Collection<int, Shop> $shops */
        $shops = $product->shops()
            ->select(['id', 'product_id', 'host', 'current_price', 'current_in_stock', 'currency', 'last_checked_at', 'url'])
            ->where('active', true)
            ->where('current_in_stock', true)
            ->where('health', '!=', ShopHealth::Dead->value)
            ->whereNotNull('current_price')
            ->orderBy('current_price')
            ->get();

        return response()
            ->view('public.product', [
                'product' => $product,
                'shops' => $shops,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
