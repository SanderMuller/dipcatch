<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use Illuminate\Support\Facades\Config;
use Locale;

/**
 * The country web discovery finds shops for: the one it searches Google in.
 * Both discovery checks ask whether a shop sells to shoppers there, because
 * a barcode search also finds shops abroad.
 */
final class ShoppersCountry
{
    /** The configured code, `nl` by default. */
    public static function code(): string
    {
        $code = mb_strtolower(trim(Config::string('dipcatch.web_discovery.country')));

        return $code === '' ? 'nl' : $code;
    }

    /** "Netherlands" for `nl`, as Jev reads it. */
    public static function name(): string
    {
        $code = strtoupper(self::code());
        $name = Locale::getDisplayRegion("-{$code}", 'en');

        return is_string($name) && $name !== '' ? $name : $code;
    }
}
