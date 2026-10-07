<?php declare(strict_types=1);

namespace App\Charts;

/** What DipCatch did about one change of the lowest price. */
enum PriceChangeAction
{
    case WrongPriceCaught;

    case Confirmed;

    case ConfirmedAlert;

    case Alert;

    case ReachedAlertPrice;

    case Rechecking;

    case RecheckFailed;

    case WentOutOfStock;

    case CouldNotRead;

    /** `$shop` is the shop the action is about, for the two that name another shop than the row's. */
    public function label(?string $shop = null): string
    {
        $shop ??= __('the cheapest shop');

        return match ($this) {
            self::WrongPriceCaught => __('Large drop. DipCatch read the shop again and the price was gone, so no alert.'),
            self::Confirmed => __('Large drop. DipCatch read the shop again and the price held.'),
            self::ConfirmedAlert => __('Large drop. DipCatch read the shop again and the price held. Price alert.'),
            self::Alert => __('Price alert'),
            self::ReachedAlertPrice => __('Reached your alert price'),
            self::Rechecking => __('Large drop. DipCatch is checking it again.'),
            self::RecheckFailed => __('Large drop. DipCatch could not read the shop again, so no alert.'),
            self::WentOutOfStock => __(':shop went out of stock.', ['shop' => $shop]),
            self::CouldNotRead => __('DipCatch could not read :shop.', ['shop' => $shop]),
        };
    }
}
