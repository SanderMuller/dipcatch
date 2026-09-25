<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\TargetPriceEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TargetPriceEvent>
 */
class TargetPriceEventFactory extends Factory
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
            'shop_id' => null,
            'currency' => function (array $attrs): string {
                $currency = Product::query()->whereKey($attrs['product_id'])->value('currency');

                return is_string($currency) ? $currency : 'EUR';
            },
            'target' => 18.00,
            'price' => 17.05,
            'fired_at' => now(),
        ];
    }

    public function unitPrice(): self
    {
        return $this->state([
            'target' => 6.00,
            'unit_price' => 5.38,
            'comparison_unit' => 'g',
            'price' => 1.29,
            'pack_quantity' => 240,
            'pack_unit' => 'g',
        ]);
    }
}
