<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->randomFloat(2, 5, 2000);
        // Unique URL per row so the (product_id, url_hash) unique key never
        // collides when factories make multiple offers for the same product.
        $url = 'https://shop.example.com/p/' . fake()->unique()->slug();

        return [
            'product_id' => Product::factory(),
            'url' => $url,
            'adapter_key' => 'jsonld',
            'currency' => 'EUR',
            'initial_price' => $price,
            'initial_checked_at' => now(),
            'current_price' => $price,
            'current_in_stock' => true,
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'last_status' => 'ok',
            'last_error' => null,
            'consecutive_failures' => 0,
            'consecutive_5xx_failures' => 0,
            'health' => 'ok',
            'active' => true,
            'notes' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }

    public function dead(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
            'health' => 'dead',
            'last_status' => 'dead',
            'consecutive_failures' => 10,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_in_stock' => false,
        ]);
    }
}
