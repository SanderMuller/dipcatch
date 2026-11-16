<?php declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Web Push endpoint at one of the browser push services.
 *
 * The queue worker sends a POST to every stored endpoint. Any other address
 * would turn a push subscription into a request from inside the app's
 * network, so only the hosts the browsers hand out are accepted, over https
 * on the default port.
 */
final class PushServiceEndpoint implements ValidationRule
{
    private const array HOSTS = [
        'fcm.googleapis.com', // Chrome, Opera, Samsung Internet
        'updates.push.services.mozilla.com', // Firefox
        'web.push.apple.com', // Safari
    ];

    /**
     * The service picks a regional host per subscription.
     */
    private const array HOST_SUFFIXES = [
        '.notify.windows.com', // Edge
    ];

    public static function allows(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        if (isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        return in_array($host, self::HOSTS, strict: true)
            || array_any(self::HOST_SUFFIXES, static fn (string $suffix): bool => str_ends_with($host, $suffix));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allows($value)) {
            $fail('The :attribute is not a browser push service address.');
        }
    }
}
