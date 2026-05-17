<?php declare(strict_types=1);

namespace App\Support;

use DateTimeZone;

/**
 * IANA timezone helper — mirrors {@see Iso4217} for the timezone vocabulary
 * used by `users.timezone` and the daily-digest scheduler.
 *
 * `DateTimeZone::listIdentifiers()` allocates ~600 strings on every call;
 * memoizing once per request keeps form renders and per-job validations
 * cheap.
 */
final class IanaTimezones
{
    /** @var list<string>|null */
    private static ?array $identifiers = null;

    /**
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return self::$identifiers ??= DateTimeZone::listIdentifiers();
    }

    /**
     * @return array<string, string> identifier => identifier
     */
    public static function options(): array
    {
        $ids = self::identifiers();

        return array_combine($ids, $ids);
    }

    public static function isValid(string $identifier): bool
    {
        return in_array($identifier, self::identifiers(), true);
    }
}
