<?php declare(strict_types=1);

namespace App\Charts;

/** What DipCatch did about one change of the lowest price. */
enum PriceChangeAction
{
    /** A large drop the shop's second reading did not show: a wrong price, and no alert. */
    case WrongPriceCaught;

    case Alert;

    case ReachedAlertPrice;

    /** A large drop waiting for its second reading. */
    case Rechecking;

    /** A large drop whose second reading never came through. */
    case RecheckFailed;

    public function label(): string
    {
        return match ($this) {
            self::WrongPriceCaught => __('Large drop. DipCatch read the shop again and the price was gone, so no alert.'),
            self::Alert => __('Price alert'),
            self::ReachedAlertPrice => __('Reached your alert price'),
            self::Rechecking => __('Large drop. DipCatch is checking it again.'),
            self::RecheckFailed => __('Large drop. DipCatch could not read the shop again, so no alert.'),
        };
    }
}
