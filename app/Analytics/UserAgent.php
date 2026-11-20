<?php declare(strict_types=1);

namespace App\Analytics;

use App\Analytics\Enums\DeviceClass;
use Jaybizzle\CrawlerDetect\CrawlerDetect;

final class UserAgent
{
    /**
     * A new detector per call: `CrawlerDetect` keeps the last match, and
     * Octane would carry a shared instance into the next request. Empty
     * headers stop it from reading `$_SERVER`, which Octane does not refresh.
     */
    public static function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return true;
        }

        return new CrawlerDetect([], $userAgent)->isCrawler($userAgent);
    }

    public static function device(string $userAgent): DeviceClass
    {
        if (preg_match('/ipad|tablet|android(?!.*mobile)/i', $userAgent) === 1) {
            return DeviceClass::Tablet;
        }

        if (preg_match('/mobi|iphone|android/i', $userAgent) === 1) {
            return DeviceClass::Mobile;
        }

        return DeviceClass::Desktop;
    }
}
