<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\StripeDispute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StripeDispute>
 */
class StripeDisputeFactory extends Factory
{
    protected $model = StripeDispute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'stripe_id' => 'dp_' . Str::random(),
            'stripe_charge_id' => 'ch_' . Str::random(),
            'amount' => 499,
            'currency' => 'EUR',
            'reason' => 'fraudulent',
            'status' => 'needs_response',
            'opened_at' => now(),
            'closed_at' => null,
        ];
    }

    public function lost(): self
    {
        return $this->state(fn (): array => [
            'status' => StripeDispute::STATUS_LOST,
            'closed_at' => now(),
        ]);
    }
}
