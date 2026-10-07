<?php declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LargeDropCheckOutcome;
use App\Models\LargeDropCheck;
use App\Models\PriceCheck;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LargeDropCheck>
 */
class LargeDropCheckFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'product_id' => fn (array $attrs): mixed => Shop::query()->whereKey($attrs['shop_id'])->value('product_id'),
            'price_check_id' => fn (array $attrs): mixed => PriceCheck::factory()->create(['shop_id' => $attrs['shop_id']])->id,
            'price' => '3.39',
            'asked_at' => now(),
            'outcome' => null,
            'resolved_at' => null,
        ];
    }

    public function rejected(): self
    {
        return $this->state(['outcome' => LargeDropCheckOutcome::Rejected, 'resolved_at' => now()]);
    }

    public function confirmed(): self
    {
        return $this->state(['outcome' => LargeDropCheckOutcome::Confirmed, 'resolved_at' => now()]);
    }
}
