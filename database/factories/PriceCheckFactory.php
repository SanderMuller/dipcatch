<?php declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceCheck>
 */
class PriceCheckFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'price' => fake()->randomFloat(2, 5, 2000),
            'currency' => 'EUR',
            'raw' => '€ 49,99',
            'status' => ScrapeStatus::Ok,
            'error' => null,
            'checked_at' => now(),
        ];
    }

    public function failed(ScrapeStatus $status = ScrapeStatus::HttpError): static
    {
        return $this->state(fn (array $attributes): array => [
            'price' => null,
            'currency' => null,
            'status' => $status,
            'error' => 'simulated failure',
        ]);
    }
}
