<?php declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Accounts one address may create per hour. Each new account sends a
     * verification mail to whatever address it names, so without a cap one
     * client could send mail to any number of strangers.
     */
    public const int REGISTRATIONS_PER_HOUR = 10;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        // Counted after validation, so a typo does not use up the budget.
        $limiterKey = 'register:' . request()->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::REGISTRATIONS_PER_HOUR)) {
            throw ValidationException::withMessages([
                'email' => __('Too many accounts were created from this network. Please try again later.'),
            ]);
        }

        RateLimiter::hit($limiterKey, 3600);

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
