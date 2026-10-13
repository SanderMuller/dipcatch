<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PriceVerdict;

/**
 * A shop's price verdict and the 90 days behind it. See {@see PriceVerdicts}.
 */
final readonly class ShopPriceVerdict
{
    /**
     * @param  numeric-string  $low  The 25th percentile of the daily prices.
     * @param  numeric-string  $median
     * @param  numeric-string  $high  The 75th percentile of the daily prices.
     */
    public function __construct(
        public PriceVerdict $verdict,
        public string $low,
        public string $median,
        public string $high,
        public bool $lowestIn90Days,
        public string $currency,
    ) {}

    /** "Usually €X to €Y for this pack", or null when the range is one price. */
    public function usualRange(): ?string
    {
        $low = MoneyFormatter::format($this->low, $this->currency);
        $high = MoneyFormatter::format($this->high, $this->currency);

        if ($low === $high) {
            return null;
        }

        return __('Usually :low to :high for this pack', ['low' => $low, 'high' => $high]);
    }
}
