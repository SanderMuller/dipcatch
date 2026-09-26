<?php declare(strict_types=1);

namespace App\Support;

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * What the dashboard adds over the product list: where to shop this week, the
 * deals that are about to stop, and what needs the user's hand.
 *
 * A fixed number of queries whatever the account's size.
 */
final readonly class DashboardDigest
{
    private const int TRIPS = 3;

    private const int TRIP_PRODUCTS = 250;

    private const int TRIP_ITEMS = 4;

    private const int ENDING_WITHIN_DAYS = 7;

    private const int ATTENTION = 4;

    /**
     * @param  list<array{host: string, shop: Shop, products: list<Product>, count: int, onOffer: int}>  $trips
     * @param  list<array{product: Product, shop: Shop, endsAt: CarbonImmutable}>  $endingSoon
     * @param  list<array{product: Product, shop: Shop}>  $failing
     * @param  list<Product>  $singleShop
     */
    private function __construct(
        public array $trips,
        public array $endingSoon,
        public array $failing,
        public array $singleShop,
    ) {}

    /**
     * Every list is bounded in SQL. Trips read up to Pro's product cap, the
     * most any account can add, so their counts stay complete.
     */
    public static function forUser(User $user, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        $tripProducts = self::activeProducts($user)
            ->with(['cheapestShop', 'shops'])
            // Products in a drop first, so a cap keeps the ones worth a trip.
            ->orderByRaw('last_notified_price is null')
            ->latest('updated_at')
            ->limit(Entitlements::of(Plan::Pro)->maxProducts() ?? self::TRIP_PRODUCTS)
            ->get();

        $inDrop = self::activeProducts($user)
            ->whereKey($tripProducts->modelKeys())
            ->inVisibleDrop()
            ->pluck('id')
            ->all();

        return new self(
            trips: self::trips($tripProducts, array_values(array_filter($inDrop, is_string(...)))),
            endingSoon: self::endingSoon($user, $now),
            failing: self::failing($user),
            singleShop: array_values(self::activeProducts($user)
                ->has('shops', '=', 1)
                ->whereHas('shops', fn (Builder $shop): Builder => $shop->where('active', true))
                ->latest()
                ->limit(self::ATTENTION)
                ->get()
                ->all()),
        );
    }

    /**
     * @return Builder<Product>
     */
    private static function activeProducts(User $user): Builder
    {
        return Product::query()->where('user_id', $user->id)->where('active', true);
    }

    /**
     * @return Builder<Shop>
     */
    private static function shopsOf(User $user): Builder
    {
        return Shop::query()
            ->tracked()
            ->where('active', true)
            ->whereHas('product', fn (Builder $product): Builder => $product->where('user_id', $user->id)->where('active', true));
    }

    /**
     * Each product under the shop the product card leads with, so a trip to
     * that shop buys every product in it at its best price.
     *
     * @param  EloquentCollection<int, Product>  $products
     * @param  array<int, string>  $inDrop
     * @return list<array{host: string, shop: Shop, products: list<Product>, count: int, onOffer: int}>
     */
    private static function trips(EloquentCollection $products, array $inDrop): array
    {
        $byHost = [];

        foreach ($products as $product) {
            $shop = HeadlinePrice::of($product)->shop;

            // Only a shop that sells it now. With none, the headline falls back
            // to the last cheapest shop, which may be sold out or switched off.
            if (! $shop instanceof Shop || ! $product->eligibleShops()->contains($shop)) {
                continue;
            }

            $byHost[$shop->host][] = [
                'product' => $product,
                'shop' => $shop,
                'onOffer' => in_array($product->id, $inDrop, true) || PromotionLabel::runningDeal($shop) !== null,
            ];
        }

        $trips = [];

        foreach ($byHost as $host => $items) {
            // Offers first, so the products a trip shows are the ones worth the trip.
            usort($items, fn (array $a, array $b): int => $b['onOffer'] <=> $a['onOffer']);

            $trips[] = [
                'host' => (string) $host,
                'shop' => $items[0]['shop'],
                'products' => array_column(array_slice($items, 0, self::TRIP_ITEMS), 'product'),
                'count' => count($items),
                'onOffer' => count(array_filter($items, fn (array $item): bool => $item['onOffer'])),
            ];
        }

        usort($trips, fn (array $a, array $b): int => [$b['count'], $b['onOffer']] <=> [$a['count'], $a['onOffer']]);

        return array_slice($trips, 0, self::TRIPS);
    }

    /**
     * @return list<array{product: Product, shop: Shop, endsAt: CarbonImmutable}>
     */
    private static function endingSoon(User $user, CarbonImmutable $now): array
    {
        $shops = self::shopsOf($user)
            ->where('promotion_ends_at', '>', $now)
            ->where('promotion_ends_at', '<=', $now->addDays(self::ENDING_WITHIN_DAYS))
            ->where(fn (Builder $started): Builder => $started->whereNull('promotion_starts_at')->orWhere('promotion_starts_at', '<=', $now))
            ->whereNotNull('current_price')
            ->where(fn (Builder $stock): Builder => $stock->whereNull('current_in_stock')->orWhere('current_in_stock', true))
            ->oldest('promotion_ends_at')
            ->with('product.shops')
            // Room for the rarer rows only the full eligibility check leaves out.
            ->limit(self::ATTENTION * 3)
            ->get();

        $ending = [];

        foreach ($shops as $shop) {
            $product = $shop->product;
            $window = $shop->promotionWindow();

            if ($product instanceof Product && $window !== null && $window->isRunning($now) && $product->eligibleShops()->contains($shop)) {
                $ending[] = ['product' => $product, 'shop' => $shop, 'endsAt' => $window->endsAt];
            }
        }

        return array_slice($ending, 0, self::ATTENTION);
    }

    /**
     * @return list<array{product: Product, shop: Shop}>
     */
    private static function failing(User $user): array
    {
        $shops = self::shopsOf($user)
            ->where('consecutive_failures', '>', 0)
            ->orderByDesc('consecutive_failures')
            ->with('product')
            ->limit(self::ATTENTION)
            ->get();

        $failing = [];

        foreach ($shops as $shop) {
            if ($shop->product instanceof Product) {
                $failing[] = ['product' => $shop->product, 'shop' => $shop];
            }
        }

        return $failing;
    }
}
