<?php declare(strict_types=1);

namespace App\Rules;

use App\Support\IanaTimezones;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validate that the value is a known IANA timezone identifier
 * (e.g. `Europe/Amsterdam`, `America/New_York`).
 *
 * Drives `users.timezone` validation on profile updates — anchoring the
 * daily 09:00-local digest dispatch.
 */
final class IanaTimezone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            // Required-ness is enforced separately; an empty value is not a timezone failure.
            return;
        }

        if (! IanaTimezones::isValid($value)) {
            $fail("The {$attribute} is not a recognised IANA timezone identifier.");
        }
    }
}
