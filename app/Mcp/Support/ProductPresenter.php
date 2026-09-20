<?php declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\Product;
use Carbon\CarbonInterface;

/**
 * One shape for a product across every tool, so an assistant reading two
 * responses sees the same field names in both.
 */
final readonly class ProductPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Product $product): array
    {
        $cheapest = $product->cheapestShop;
        $bundle = $cheapest?->liveBundleOffer();
        $packs = $product->comparablePacks();
        $bestValue = $product->bestValueShop();

        return [
            'product_id' => self::id($product->getKey()),
            'title' => $product->title,
            'currency' => $product->currency,
            // The pack price of the shop with the smallest outlay — what the
            // shopper hands over. `best_value_*` answers the other question:
            // which shop is cheapest per kilo, litre or piece, which is the
            // basis a drop alert fires on. They are often different shops.
            'cheapest_price' => self::decimal($product->cheapest_price),
            // Resolved here rather than read back from the stored columns.
            // Those are written by a recompute, so a product answers null for
            // them until its next price check — and null reads as "this product
            // has no per-unit answer", which is a different and wrong statement.
            'best_value_shop_id' => $bestValue === null ? null : self::id($bestValue->getKey()),
            'best_value_price' => self::decimal($bestValue?->current_price),
            'best_value_unit_price' => $bestValue === null ? null : $packs->unitPriceOf($bestValue),
            'comparison_unit' => $packs->unit(),
            'cheapest_single_item_price' => $cheapest?->singleItemPrice(),
            'cheapest_bundle_quantity' => $bundle?->quantity,
            'cheapest_bundle_total_price' => $bundle?->totalPrice,
            'shop_count' => $product->shops()->count(),
            'threshold_pct' => self::decimal($product->drop_threshold_pct),
            'threshold_abs' => self::decimal($product->drop_threshold_abs),
            'target_price' => self::decimal($product->target_price),
            'unit_price_target' => self::decimal($product->unit_price_target),
            // Whether the headline price can actually be bought. Without it a
            // caller has to cross-reference `is_cheapest` against each shop's
            // stock before it can say "cheapest is X" honestly.
            'cheapest_stock' => self::stock($product->cheapestShop?->current_in_stock),
            'image_url' => $product->safeImageUrl(),
            'category' => $product->category?->value,
            'category_label' => $product->category?->label(),
            'department' => $product->category?->department()->value,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shops(Product $product): array
    {
        $rows = [];
        $packs = $product->comparablePacks();

        foreach ($product->shops as $shop) {
            $pack = $packs->for($shop);
            $host = parse_url($shop->url, PHP_URL_HOST);
            $checked = $shop->last_checked_at;

            $rows[] = [
                'shop_id' => self::id($shop->getKey()),
                'host' => is_string($host) ? $host : null,
                'url' => $shop->url,
                'price' => self::decimal($shop->current_price),
                'single_item_price' => $shop->singleItemPrice(),
                'bundle_quantity' => $shop->liveBundleOffer()?->quantity,
                'bundle_total_price' => $shop->liveBundleOffer()?->totalPrice,
                'in_stock' => $shop->current_in_stock,
                'stock' => self::stock($shop->current_in_stock),
                'pack_quantity' => $shop->pack_quantity === null ? null : (float) $shop->pack_quantity,
                'pack_unit' => $shop->pack_unit,
                // Which shops can supply a picture, so a caller choosing one
                // for set_image can see the choice rather than guess at it.
                'has_image' => $shop->safeImageUrl() !== null,
                // Which reader produced this price. Some hosts have two, and
                // they do not see the same things: the AH API carries the
                // bonus mechanics, the daily dataset carries none of them. A
                // shop quietly read from the dataset shows a plausible price
                // with no promotion on it, and nothing about the row says so.
                'read_by' => $shop->adapter_key,
                // What this shop's pack resolves to for *this* product, and
                // where the number came from. `excluded_reason` is the whole
                // point: a shop with no unit price is never silently absent
                // from the comparison, it says which fact is missing.
                'comparable_quantity' => $pack?->size?->quantity,
                'comparable_unit' => $pack?->size?->unit,
                'pack_size_provenance' => $pack?->provenance?->value,
                'unit_price' => $packs->unitPriceOf($shop),
                'excluded_reason' => $pack?->reason(),
                'is_cheapest' => $shop->getKey() === $product->cheapest_shop_id,
                'is_best_value' => $shop->getKey() === $product->best_value_shop_id,
                'last_checked_at' => $checked instanceof CarbonInterface ? $checked->toIso8601String() : null,
            ];
        }

        return $rows;
    }

    /**
     * `in_stock` is nullable, and a reader that treats null as false would
     * be as wrong as one that treats it as true. This says which it is.
     */
    private static function stock(?bool $inStock): string
    {
        return match ($inStock) {
            true => 'in_stock',
            false => 'out_of_stock',
            null => 'unknown',
        };
    }

    /**
     * Model keys are UUID strings here, but `getKey()` is typed mixed.
     */
    private static function id(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * Decimal columns come back as strings or numerics depending on the
     * driver; every price in this codebase travels as a decimal string.
     */
    private static function decimal(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Product $product): array
    {
        return [
            ...$this->summary($product),
            'shops' => $this->shops($product),
        ];
    }
}
