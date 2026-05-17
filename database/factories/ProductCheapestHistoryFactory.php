<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCheapestHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCheapestHistory>
 */
class ProductCheapestHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'cheapest_shop_id' => null,
            'cheapest_price' => fake()->randomFloat(2, 5, 2000),
            'started_at' => now(),
            'ended_at' => null,
            'triggering_price_check_id' => null,
        ];
    }
}
