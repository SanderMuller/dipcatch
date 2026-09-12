<?php declare(strict_types=1);

namespace App\Enums;

/**
 * The social identity providers DipCatch accepts on the login page.
 *
 * The value is the Socialite driver name, so it doubles as the route
 * parameter. Anything outside this list never reaches the controller —
 * routes/web.php constrains `{provider}` to these values.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Apple = 'apple';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $provider): string => $provider->value, self::cases());
    }

    /**
     * The providers with a complete set of credentials behind them.
     *
     * The login and register pages read this and show only the buttons that
     * work. A half-configured provider stays hidden rather than sending the
     * user out to an error page it cannot act on, and its routes 404.
     *
     * @return list<self>
     */
    public static function configured(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $provider): bool => $provider->isConfigured()));
    }

    public function isConfigured(): bool
    {
        return match ($this) {
            self::Google => $this->hasAll('client_id', 'client_secret', 'redirect'),

            // Apple mints its client secret per request from the .p8 key, so
            // either a ready-made secret or the whole key trio will do — see
            // the provider's `getJwtConfig()`, which only mints one when
            // `private_key` is set, and `AppleToken`, which reads the rest.
            self::Apple => $this->hasAll('client_id', 'redirect')
                && ($this->has('client_secret') || $this->hasAll('private_key', 'key_id', 'team_id')),
        };
    }

    private function hasAll(string ...$keys): bool
    {
        return array_all($keys, fn (string $key): bool => $this->has($key));
    }

    private function has(string $key): bool
    {
        $value = config("services.{$this->value}.{$key}");

        return is_string($value) && trim($value) !== '';
    }

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Apple => 'Apple',
        };
    }
}
