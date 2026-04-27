<?php declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScrapeStatus;
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
        $price = fake()->randomFloat(2, 5, 2000);

        return [
            'user_id' => User::factory(),
            'url' => fake()->url(),
            'title' => fake()->sentence(3),
            'image_url' => fake()->imageUrl(),
            'price_selector' => '.product-price',
            'fallback_selectors' => [],
            'image_selector' => null,
            'title_selector' => null,
            'currency' => 'EUR',
            'initial_price' => $price,
            'initial_checked_at' => now(),
            'last_price' => $price,
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'last_status' => ScrapeStatus::Ok,
            'last_error' => null,
            'drop_threshold_pct' => 10.00,
            'drop_threshold_abs' => 5.00,
            'last_notified_price' => null,
            'last_notified_at' => null,
            'needs_js' => false,
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
