<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Product;
use App\Support\MoneyFormatter;
use App\Support\Numeric;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;

/** The alert fields the edit page and the add-product wizard share. */
trait EditsAlertFields
{
    public ?string $dropThresholdPct = null;

    public ?string $dropThresholdAbs = null;

    public ?string $targetPrice = null;

    public ?string $unitPriceTarget = null;

    protected function loadAlertFields(Product $product): void
    {
        $this->dropThresholdPct = $product->drop_threshold_pct === null ? null : (string) $product->drop_threshold_pct;
        $this->dropThresholdAbs = $product->drop_threshold_abs === null ? null : (string) $product->drop_threshold_abs;
        $this->targetPrice = $product->target_price === null ? null : (string) $product->target_price;
        // The column keeps four decimals, which the form would show as 7.0000.
        $this->unitPriceTarget = $product->unit_price_target === null ? null : Numeric::trimmed((string) $product->unit_price_target);
    }

    /**
     * @return array<string, FluentRuleContract>
     */
    protected function alertFieldRules(): array
    {
        return [
            // A threshold of zero would alert on a price that did not move.
            'dropThresholdPct' => FluentRule::numeric('Alert me when it drops by (%)')
                ->nullable()
                ->between(0.01, 99.98999999999999),
            'dropThresholdAbs' => FluentRule::numeric('Alert me when it drops by (amount)')->nullable()->min(0.01),
            'targetPrice' => FluentRule::numeric('Target price')->nullable()->min(0.01),
            // A cent is a floor for money and a ceiling for a rate: a tablet
            // costs three hundredths of one, and a target above every real
            // value cannot be set at all.
            'unitPriceTarget' => FluentRule::numeric('Target price per kilo, litre or piece')->nullable()->min(0.0001),
        ];
    }

    /**
     * The columns the fields write, an empty field as null.
     *
     * @return array{drop_threshold_pct: ?string, drop_threshold_abs: ?string, target_price: ?string, unit_price_target: ?string}
     */
    protected function alertFieldValues(): array
    {
        return [
            'drop_threshold_pct' => self::blankToNull($this->dropThresholdPct),
            'drop_threshold_abs' => self::blankToNull($this->dropThresholdAbs),
            'target_price' => self::blankToNull($this->targetPrice),
            'unit_price_target' => self::blankToNull($this->unitPriceTarget),
        ];
    }

    /**
     * The "Other alerts" fold's summary line: one phrase per alert set there.
     * The fold opens when this is not empty, so no active alert hides.
     *
     * @return list<string>
     */
    protected function otherAlertSummaries(string $currency): array
    {
        return array_values(array_filter([
            ($amount = self::blankToNull($this->targetPrice)) === null ? null : __(':amount for any pack', ['amount' => MoneyFormatter::format($amount, $currency)]),
            ($percent = self::blankToNull($this->dropThresholdPct)) === null ? null : __(':percent% drop', ['percent' => Numeric::trimmed($percent)]),
            ($drop = self::blankToNull($this->dropThresholdAbs)) === null ? null : __(':amount drop', ['amount' => MoneyFormatter::format($drop, $currency)]),
        ], is_string(...)));
    }

    private static function blankToNull(?string $value): ?string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed === '' ? null : $trimmed;
    }
}
