<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

/**
 * The account's shopping list, grouped by the shop where each product is the
 * best buy now: the shop the product card and the dashboard's trips lead
 * with.
 *
 * Shops the user is not going to can be skipped. Each product then goes to its
 * best buy among the shops that are left, by the same rule, so a trip to the
 * remaining shops still buys everything at its lowest price there. A product
 * only the skipped shops sell goes into its own group, and a product no shop
 * sells now into the last one.
 *
 * Three queries whatever the list's size: the products, their shops and their
 * cheapest shop. Everything after that reads the loaded shops.
 *
 * @phpstan-type Item array{product: Product, headline: HeadlinePrice, shop: ?Shop, skippedBest: ?Shop, crossedOff: bool}
 * @phpstan-type Group array{host: string, shop: ?Shop, skipped: bool, items: list<Item>, open: int}
 */
final readonly class ShoppingList
{
    private const string UNAVAILABLE = '';

    private const string ONLY_SKIPPED = "\0skipped";

    /**
     * @param  list<Group>  $groups
     * @param  list<array{host: string, items: int}>  $shops  Every shop that sells an open item now: the ones to pick from.
     */
    private function __construct(
        public array $groups,
        public int $openCount,
        public int $crossedOffCount,
        public array $shops,
    ) {}

    /**
     * @param  list<string>  $skip  Hosts the user is not going to.
     */
    public static function forUser(User $user, array $skip = []): self
    {
        $products = Product::query()
            ->where('user_id', $user->id)
            ->onShoppingList()
            ->with(['cheapestShop', 'shops'])
            ->orderBy('listed_at')
            ->orderBy('id')
            ->get();

        /** @var array<string, array{shop: ?Shop, items: list<Item>}> $byKey */
        $byKey = [];

        foreach ($products as $product) {
            $item = self::item($product, $skip);
            $key = $item['shop']->host ?? ($item['skippedBest'] === null ? self::UNAVAILABLE : self::ONLY_SKIPPED);

            $byKey[$key] ??= ['shop' => $item['shop'], 'items' => []];
            $byKey[$key]['items'][] = $item;
        }

        $groups = [];

        foreach ($byKey as $key => $group) {
            $items = $group['items'];

            // Open items first, then the crossed-off ones, each in the order
            // they went on the list. usort is stable, so that order holds.
            usort($items, fn (array $a, array $b): int => $a['crossedOff'] <=> $b['crossedOff']);

            $groups[] = [
                'host' => $key === self::ONLY_SKIPPED ? '' : (string) $key,
                'shop' => $group['shop'],
                'skipped' => $key === self::ONLY_SKIPPED,
                'items' => $items,
                'open' => count(array_filter($items, fn (array $item): bool => ! $item['crossedOff'])),
            ];
        }

        $crossedOff = $products->filter(fn (Product $product): bool => $product->isCrossedOff())->count();

        return new self(
            self::inShoppingOrder($groups),
            $products->count() - $crossedOff,
            $crossedOff,
            self::shopsSellingOpenItems(array_values($products->all())),
        );
    }

    /**
     * The shops with the most to buy first, then by host. The products only
     * skipped shops sell come after them, and the products no shop sells now
     * last, whatever their size; an empty host would otherwise sort first.
     *
     * @param  list<Group>  $groups
     * @return list<Group>
     */
    private static function inShoppingOrder(array $groups): array
    {
        $rank = fn (array $group): int => match (true) {
            $group['skipped'] => 1,
            $group['host'] === '' => 2,
            default => 0,
        };

        usort($groups, fn (array $a, array $b): int => [$rank($a), $b['open'], $a['host']] <=> [$rank($b), $a['open'], $b['host']]);

        return $groups;
    }

    /**
     * One product as a list shows it: its headline, and the shop to buy it at
     * now. With no such shop there is no price to show either, because the
     * headline would fall back to a last price nobody can pay.
     *
     * With shops skipped, the headline is read from the other shops only, on
     * a copy of the product. `skippedBest` names the best buy the skip took
     * away when no other shop sells the product.
     *
     * @param  list<string>  $skip
     * @return Item
     */
    public static function item(Product $product, array $skip = []): array
    {
        $skippedBest = null;

        if ($skip !== [] && $product->shops->contains(fn (Shop $shop): bool => in_array($shop->host, $skip, true))) {
            $everywhere = HeadlinePrice::of($product)->buyableShop();

            $product = clone $product;
            $product->setRelation('shops', $product->shops->reject(fn (Shop $shop): bool => in_array($shop->host, $skip, true))->values());

            $headline = HeadlinePrice::of($product);
            $skippedBest = $headline->buyableShop() === null ? $everywhere : null;
        } else {
            $headline = HeadlinePrice::of($product);
        }

        return [
            'product' => $product,
            'headline' => $headline,
            'shop' => $headline->buyableShop(),
            'skippedBest' => $skippedBest,
            'crossedOff' => $product->isCrossedOff(),
        ];
    }

    /**
     * Every shop that sells an open item now, read from all of a product's
     * shops, so a skipped shop stays on offer to be picked again.
     *
     * @param  list<Product>  $products
     * @return list<array{host: string, items: int}>
     */
    private static function shopsSellingOpenItems(array $products): array
    {
        $counts = [];

        foreach ($products as $product) {
            if ($product->isCrossedOff()) {
                continue;
            }

            $hosts = array_unique($product->eligibleShops()->map(fn (Shop $shop): string => $shop->host)->all());

            foreach ($hosts as $host) {
                $counts[$host] = ($counts[$host] ?? 0) + 1;
            }
        }

        $shops = [];

        foreach ($counts as $host => $items) {
            $shops[] = ['host' => (string) $host, 'items' => $items];
        }

        usort($shops, fn (array $a, array $b): int => [$b['items'], $a['host']] <=> [$a['items'], $b['host']]);

        return $shops;
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }
}
