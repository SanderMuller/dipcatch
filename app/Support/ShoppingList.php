<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

/**
 * The account's shopping list, grouped by the shop where each product is the
 * best buy now: the shop the product card and the dashboard's trips lead
 * with. A product no shop sells now goes into one last group.
 *
 * Three queries whatever the list's size: the products, their shops and their
 * cheapest shop. Everything after that reads the loaded shops.
 *
 * @phpstan-type Item array{product: Product, headline: HeadlinePrice, shop: ?Shop, crossedOff: bool}
 * @phpstan-type Group array{host: string, shop: ?Shop, items: list<Item>, open: int}
 */
final readonly class ShoppingList
{
    /**
     * @param  list<Group>  $groups
     */
    private function __construct(
        public array $groups,
        public int $openCount,
        public int $crossedOffCount,
    ) {}

    public static function forUser(User $user): self
    {
        $products = Product::query()
            ->where('user_id', $user->id)
            ->onShoppingList()
            ->with(['cheapestShop', 'shops'])
            ->orderBy('listed_at')
            ->orderBy('id')
            ->get();

        /** @var array<string, array{shop: ?Shop, items: list<Item>}> $byHost */
        $byHost = [];

        foreach ($products as $product) {
            $item = self::item($product);
            $host = $item['shop']->host ?? '';

            $byHost[$host] ??= ['shop' => $item['shop'], 'items' => []];
            $byHost[$host]['items'][] = $item;
        }

        $groups = [];

        foreach ($byHost as $host => $group) {
            $items = $group['items'];

            // Open items first, then the crossed-off ones, each in the order
            // they went on the list. usort is stable, so that order holds.
            usort($items, fn (array $a, array $b): int => $a['crossedOff'] <=> $b['crossedOff']);

            $groups[] = [
                'host' => (string) $host,
                'shop' => $group['shop'],
                'items' => $items,
                'open' => count(array_filter($items, fn (array $item): bool => ! $item['crossedOff'])),
            ];
        }

        $crossedOff = $products->filter(fn (Product $product): bool => $product->isCrossedOff())->count();

        return new self(self::inShoppingOrder($groups), $products->count() - $crossedOff, $crossedOff);
    }

    /**
     * The shops with the most to buy first, then by host. The group of
     * products no shop sells now comes last whatever its size, and its empty
     * host would otherwise sort first on a tie.
     *
     * @param  list<Group>  $groups
     * @return list<Group>
     */
    private static function inShoppingOrder(array $groups): array
    {
        usort($groups, fn (array $a, array $b): int => [$a['host'] === '', $b['open'], $a['host']] <=> [$b['host'] === '', $a['open'], $b['host']]);

        return $groups;
    }

    /**
     * One product as a list shows it: its headline, and the shop to buy it at
     * now. With no such shop there is no price to show either, because the
     * headline would fall back to a last price nobody can pay.
     *
     * @return Item
     */
    public static function item(Product $product): array
    {
        $headline = HeadlinePrice::of($product);

        return [
            'product' => $product,
            'headline' => $headline,
            'shop' => $headline->buyableShop(),
            'crossedOff' => $product->isCrossedOff(),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }
}
