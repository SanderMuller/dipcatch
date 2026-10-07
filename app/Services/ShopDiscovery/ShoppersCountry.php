<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Models\Product;
use App\Models\User;
use App\Support\Iso4217;
use DateTimeZone;
use Illuminate\Support\Facades\Config;
use IntlTimeZone;
use Locale;
use NumberFormatter;

/**
 * The country web discovery finds shops for: the one it searches Google in.
 * It is the owner's: the country they picked in Settings, else the one their
 * timezone lies in, else the configured fallback. Both discovery checks ask
 * whether a shop sells to shoppers there, because a barcode search also finds
 * shops abroad.
 */
final class ShoppersCountry
{
    /**
     * The search language per country, where it is not English. Belgium and
     * Switzerland search in the language most of their shoppers read.
     */
    private const array LANGUAGES = [
        'nl' => 'nl', 'be' => 'nl',
        'fr' => 'fr', 'lu' => 'fr',
        'de' => 'de', 'at' => 'de', 'ch' => 'de',
        'se' => 'sv', 'no' => 'no', 'dk' => 'da', 'fi' => 'fi',
        'es' => 'es', 'it' => 'it', 'pt' => 'pt', 'pl' => 'pl',
        'cz' => 'cs', 'sk' => 'sk', 'hu' => 'hu', 'ro' => 'ro',
        'gr' => 'el', 'si' => 'sl', 'hr' => 'hr', 'ee' => 'et',
        'lv' => 'lv', 'lt' => 'lt', 'bg' => 'bg',
    ];

    /** Where the bundled ICU data is older than the currency: Bulgaria joined the euro in 2026. */
    private const array CURRENCIES = ['bg' => 'EUR'];

    /**
     * Built once per process: the list follows the bundled timezone data only.
     *
     * @var array<string, string>|null
     */
    private static ?array $options = null;

    /** The owner's country code, lowercase: `nl`, `fr`. */
    public static function of(?User $user): string
    {
        if (! $user instanceof User) {
            return self::fallback();
        }

        if (is_string($user->country) && self::isKnown($user->country)) {
            return $user->country;
        }

        return self::fromTimezone($user->timezone) ?? self::fallback();
    }

    /** The country of the product's owner. */
    public static function forProduct(Product $product): string
    {
        $product->loadMissing('user');

        return self::of($product->user);
    }

    /** The configured code, `nl` by default. */
    public static function fallback(): string
    {
        $code = mb_strtolower(trim(Config::string('dipcatch.web_discovery.country')));

        return $code === '' ? 'nl' : $code;
    }

    /** The country a timezone lies in. Null for UTC and the other zones of no country. */
    public static function fromTimezone(?string $timezone): ?string
    {
        if ($timezone === null || $timezone === '') {
            return null;
        }

        $region = IntlTimeZone::getRegion($timezone);

        return is_string($region) && preg_match('/^[A-Z]{2}$/', $region) === 1 && self::isKnown(mb_strtolower($region))
            ? mb_strtolower($region)
            : null;
    }

    /**
     * The currency its shoppers pay in, as `Iso4217` writes it: `SEK` for
     * `se`. Null for one no product can be priced in.
     */
    public static function currency(string $code): ?string
    {
        $currency = self::CURRENCIES[$code]
            ?? new NumberFormatter('en_' . strtoupper($code), NumberFormatter::CURRENCY)->getTextAttribute(NumberFormatter::CURRENCY_CODE);

        return is_string($currency) && in_array($currency, Iso4217::CODES, strict: true) ? $currency : null;
    }

    /** "Netherlands" for `nl`, as Jev reads it. */
    public static function name(string $code): string
    {
        $name = Locale::getDisplayRegion('-' . strtoupper($code), 'en');

        return is_string($name) && $name !== '' ? $name : strtoupper($code);
    }

    /** The language Google is searched in for the country. */
    public static function language(string $code): string
    {
        if ($code === self::fallback()) {
            $configured = mb_strtolower(trim(Config::string('dipcatch.web_discovery.language')));

            if ($configured !== '') {
                return $configured;
            }
        }

        return self::LANGUAGES[$code] ?? 'en';
    }

    /** Whether the code is a country with a timezone of its own. */
    public static function isKnown(string $code): bool
    {
        return array_key_exists($code, self::options());
    }

    /**
     * Every country with a timezone of its own, code => English name, by name.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        if (self::$options !== null) {
            return self::$options;
        }

        $options = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $region = IntlTimeZone::getRegion($identifier);

            if (is_string($region) && preg_match('/^[A-Z]{2}$/', $region) === 1) {
                $code = mb_strtolower($region);
                $options[$code] ??= self::name($code);
            }
        }

        asort($options);

        return self::$options = $options;
    }
}
