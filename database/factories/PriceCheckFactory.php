<?php declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Shop;
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
            'shop_id' => Shop::factory(),
            'price' => fake()->randomFloat(2, 5, 2000),
            'currency' => 'EUR',
            'in_stock' => true,
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
            'in_stock' => null,
            'status' => $status,
            'error' => 'simulated failure',
        ]);
    }
}
