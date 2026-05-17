<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'image_url' => fake()->imageUrl(),
            'currency' => 'EUR',
            'drop_threshold_pct' => 10.00,
            'drop_threshold_abs' => 5.00,
            'last_notified_price' => null,
            'last_notified_at' => null,
            'cheapest_shop_id' => null,
            'cheapest_price' => null,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
