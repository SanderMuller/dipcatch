<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * What the dashboard adds over the product list: where to shop this week, the
 * deals that are about to stop, and what needs the user's hand.
 *
 * Built from one list of active products with their shops loaded, so the
 * dashboard costs the same number of queries for ten products as for one.
 */
final readonly class DashboardDigest
{
    private const int TRIPS = 3;

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
     * @param  EloquentCollection<int, Product>  $products  Active products, with `shops` loaded.
     * @param  array<int, string>  $inDrop  Ids of the products in a drop, as {@see Product::inVisibleDrop()} finds them.
     */
    public static function of(EloquentCollection $products, array $inDrop = [], ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        return new self(
            trips: self::trips($products, $inDrop),
            endingSoon: self::endingSoon($products, $now),
            failing: self::failing($products),
            singleShop: array_values($products
                ->filter(fn (Product $product): bool => $product->shops->count() === 1 && $product->shops->first()?->active === true)
                ->take(self::ATTENTION)
                ->all()),
        );
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
     * @param  EloquentCollection<int, Product>  $products
     * @return list<array{product: Product, shop: Shop, endsAt: CarbonImmutable}>
     */
    private static function endingSoon(EloquentCollection $products, CarbonImmutable $now): array
    {
        $ending = [];
        $cutoff = $now->addDays(self::ENDING_WITHIN_DAYS);

        foreach ($products as $product) {
            foreach ($product->eligibleShops() as $shop) {
                $window = $shop->promotionWindow();

                if ($window !== null && $window->isRunning($now) && $window->endsAt <= $cutoff) {
                    $ending[] = ['product' => $product, 'shop' => $shop, 'endsAt' => $window->endsAt];
                }
            }
        }

        usort($ending, fn (array $a, array $b): int => $a['endsAt'] <=> $b['endsAt']);

        return array_slice($ending, 0, self::ATTENTION);
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @return list<array{product: Product, shop: Shop}>
     */
    private static function failing(EloquentCollection $products): array
    {
        $failing = [];

        foreach ($products as $product) {
            foreach ($product->shops as $shop) {
                if ($shop->active && $shop->readsAreFailing()) {
                    $failing[] = ['product' => $product, 'shop' => $shop];
                }
            }
        }

        return array_slice($failing, 0, self::ATTENTION);
    }
}
