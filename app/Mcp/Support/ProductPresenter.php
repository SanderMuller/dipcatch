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
        return [
            'product_id' => self::id($product->getKey()),
            'title' => $product->title,
            'currency' => $product->currency,
            'cheapest_price' => self::decimal($product->cheapest_price),
            'shop_count' => $product->shops()->count(),
            'threshold_pct' => self::decimal($product->drop_threshold_pct),
            'threshold_abs' => self::decimal($product->drop_threshold_abs),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shops(Product $product): array
    {
        $rows = [];

        foreach ($product->shops as $shop) {
            $host = parse_url($shop->url, PHP_URL_HOST);
            $checked = $shop->last_checked_at;

            $rows[] = [
                'shop_id' => self::id($shop->getKey()),
                'host' => is_string($host) ? $host : null,
                'url' => $shop->url,
                'price' => self::decimal($shop->current_price),
                'in_stock' => $shop->current_in_stock,
                'pack_quantity' => $shop->pack_quantity === null ? null : (float) $shop->pack_quantity,
                'pack_unit' => $shop->pack_unit,
                'is_cheapest' => $shop->getKey() === $product->cheapest_shop_id,
                'last_checked_at' => $checked instanceof CarbonInterface ? $checked->toIso8601String() : null,
            ];
        }

        return $rows;
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
