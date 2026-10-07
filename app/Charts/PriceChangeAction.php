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

    public function label(): string
    {
        return match ($this) {
            self::WrongPriceCaught => __('Large drop. DipCatch read the shop again and the price was gone, so no alert.'),
            self::Confirmed => __('Large drop. DipCatch read the shop again and the price held.'),
            self::ConfirmedAlert => __('Large drop. DipCatch read the shop again and the price held. Price alert.'),
            self::Alert => __('Price alert'),
            self::ReachedAlertPrice => __('Reached your alert price'),
            self::Rechecking => __('Large drop. DipCatch is checking it again.'),
            self::RecheckFailed => __('Large drop. DipCatch could not read the shop again, so no alert.'),
        };
    }
}
