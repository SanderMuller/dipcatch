<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\StripePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StripePayment>
 */
class StripePaymentFactory extends Factory
{
    protected $model = StripePayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'stripe_id' => 'in_' . Str::random(16),
            'kind' => StripePayment::KIND_PAYMENT,
            'amount' => 499,
            'currency' => 'EUR',
            'occurred_at' => now(),
        ];
    }

    public function refund(): self
    {
        return $this->state(fn (): array => [
            'kind' => StripePayment::KIND_REFUND,
            'stripe_id' => 'ch_' . Str::random(16),
            'amount' => -499,
        ]);
    }
}
