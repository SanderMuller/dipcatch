<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceDropEvent>
 */
class PriceDropEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => function (array $attrs): int {
                $userId = Product::query()->whereKey($attrs['product_id'])->value('user_id');

                return is_int($userId) ? $userId : (is_numeric($userId) ? (int) $userId : 0);
            },
            'price_check_id' => function (array $attrs): int {
                $shop = Shop::factory()->create(['product_id' => $attrs['product_id']]);

                return PriceCheck::factory()
                    ->create(['shop_id' => $shop->id])
                    ->id;
            },
            'triggered_by_shop_id' => null,
            'notification_id' => null,
            'currency' => function (array $attrs): string {
                $currency = Product::query()->whereKey($attrs['product_id'])->value('currency');

                return is_string($currency) ? $currency : 'EUR';
            },
            'reference_price' => 100.00,
            'reference_kind' => 'median_30d',
            'new_price' => 80.00,
            'drop_pct' => 20.0000,
            'drop_abs' => 20.00,
            'fired_at' => now(),
        ];
    }
}
