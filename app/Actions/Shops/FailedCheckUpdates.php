<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Models\Shop;
use Illuminate\Support\Facades\Config;

/**
 * The shop columns a failed price check writes: the failure counters, and
 * the health they lead to.
 */
final readonly class FailedCheckUpdates
{
    /**
     * @return array{consecutive_failures?: int, consecutive_5xx_failures?: int, health?: string, active?: bool}
     */
    public static function for(Shop $shop, ScrapeStatus $status, bool $countsAgainstShop): array
    {
        if (! $countsAgainstShop) {
            return [];
        }

        // Permanent: both counters are kept, the offer dies now.
        if ($status === ScrapeStatus::RobotsDisallowed) {
            return ['health' => ShopHealth::Dead->value, 'active' => false];
        }

        if ($status === ScrapeStatus::TransientServerError) {
            $failures = $shop->consecutive_5xx_failures + 1;

            return ['consecutive_5xx_failures' => $failures] + self::healthAt(
                $failures,
                Config::integer('dipcatch.shop.failing_5xx_after'),
                Config::integer('dipcatch.shop.dead_5xx_after'),
            );
        }

        $failures = $shop->consecutive_failures + 1;

        return ['consecutive_failures' => $failures] + self::healthAt(
            $failures,
            Config::integer('dipcatch.shop.failing_after'),
            Config::integer('dipcatch.shop.dead_after'),
        );
    }

    /**
     * @return array{health?: string, active?: bool}
     */
    private static function healthAt(int $failures, int $failingAfter, int $deadAfter): array
    {
        if ($failures >= $deadAfter) {
            return ['health' => ShopHealth::Dead->value, 'active' => false];
        }

        if ($failures >= $failingAfter) {
            return ['health' => ShopHealth::Failing->value];
        }

        return [];
    }
}
