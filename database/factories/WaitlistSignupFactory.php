<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\WaitlistSignup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistSignup>
 */
class WaitlistSignupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
