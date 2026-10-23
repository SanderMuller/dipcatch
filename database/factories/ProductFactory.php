<?php declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\UnitTargetConversion;
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
            // Not `fake()->imageUrl()`: that points at via.placeholder.com,
            // which no longer resolves, so every seeded product rendered a
            // broken frame.
            'image_url' => 'https://placehold.co/600x600/e4e4e7/52525b.png?text=' . rawurlencode(fake()->word()),
            'currency' => 'EUR',
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

    /**
     * A target per pack, pinned to a shop made for it: the alert a product
     * with no size gets.
     *
     * @param  array<string, mixed>  $shop
     */
    public function withPackTarget(string $price, array $shop = []): static
    {
        return $this->afterCreating(function (Product $product) use ($price, $shop): void {
            $pinned = Shop::factory()->for($product)->create($shop);

            $product->forceFill([
                'unit_price_target' => $price,
                'unit_price_target_unit' => UnitTargetConversion::PACK,
                'unit_price_target_shop_id' => $pinned->id,
            ])->save();
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }

    public function listed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'listed_at' => now(),
            'list_checked_at' => null,
        ]);
    }

    public function listedAndCrossedOff(): static
    {
        return $this->state(fn (array $attributes): array => [
            'listed_at' => now()->subHour(),
            'list_checked_at' => now(),
        ]);
    }
}
