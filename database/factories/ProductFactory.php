<?php declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategorySource;
use App\Enums\ProductCategory;
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
            'share_slug' => null,
            'active' => true,
            'category' => null,
            'category_set_by' => null,
        ];
    }

    public function categorised(ProductCategory $category, CategorySource $source = CategorySource::User): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => $category,
            'category_set_by' => $source,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
