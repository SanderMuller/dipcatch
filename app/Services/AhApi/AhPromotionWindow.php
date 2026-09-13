<?php declare(strict_types=1);

namespace App\Services\AhApi;

use App\PriceAdapters\PromotionWindow;
use App\Support\DutchDate;

final class AhPromotionWindow
{
    /**
     * Read AH's bonus period. Missing boundaries stay open. Supplied invalid
     * boundaries reject the window so malformed dates cannot enable a bundle.
     *
     * @param  array<mixed>  $card
     */
    public static function fromCard(array $card): ?PromotionWindow
    {
        if (data_get($card, 'isBonus') !== true) {
            return null;
        }

        $mechanism = data_get($card, 'bonusMechanism');
        $startsAt = DutchDate::startOfDay(data_get($card, 'bonusStartDate'));
        $endsAt = DutchDate::endOfDay(data_get($card, 'bonusEndDate'));
        $dateValues = [
            'bonusStartDate' => $startsAt,
            'bonusEndDate' => $endsAt,
        ];

        if (array_any(
            array_keys($dateValues),
            fn (string $key): bool => array_key_exists($key, $card) && $dateValues[$key] === null,
        )) {
            return null;
        }

        return PromotionWindow::make(
            endsAt: $endsAt,
            startsAt: $startsAt,
            label: is_string($mechanism) ? $mechanism : null,
        );
    }
}
