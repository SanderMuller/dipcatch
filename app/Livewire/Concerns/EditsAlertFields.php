<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Product;
use App\Support\MoneyFormatter;
use App\Support\Numeric;
use App\Support\UnitWord;
use Livewire\Attributes\Locked;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;

/** The alert fields the edit page and the add-product wizard share. */
trait EditsAlertFields
{
    public ?string $dropThresholdPct = null;

    public ?string $dropThresholdAbs = null;

    public ?string $targetPrice = null;

    public ?string $unitPriceTarget = null;

    /**
     * The unit the per-unit field is shown in: the product's comparison unit
     * when the form loaded. A changed target is stored in it, so a price check
     * that moves the product to another unit while the form is open does not
     * change what the typed number means.
     */
    #[Locked]
    public ?string $unitPriceTargetUnit = null;

    /** The per-unit field as it loaded. Saving it unchanged leaves the owner's target alone. */
    #[Locked]
    public ?string $loadedUnitPriceTarget = null;

    /** Set by "Remove target" on a suspended target, which the field cannot show. */
    public bool $removeUnitPriceTarget = false;

    protected function loadAlertFields(Product $product): void
    {
        $this->dropThresholdPct = $product->drop_threshold_pct === null ? null : (string) $product->drop_threshold_pct;
        $this->dropThresholdAbs = $product->drop_threshold_abs === null ? null : (string) $product->drop_threshold_abs;
        $this->targetPrice = $product->target_price === null ? null : (string) $product->target_price;
        $this->loadUnitPriceTarget($product);
    }

    /**
     * The per-unit target in today's comparison unit, which is the unit every
     * helper beside the field — the pack prices, the history, a suggestion —
     * works in. A target that cannot be expressed in it loads empty; the page
     * shows it in its own unit beside the field instead.
     */
    protected function loadUnitPriceTarget(Product $product): void
    {
        $effective = $product->effectiveUnitPriceTarget();
        // The column keeps four decimals, which the form would show as 7.0000.
        $this->unitPriceTarget = $effective === null ? null : Numeric::trimmed($effective);
        $this->unitPriceTargetUnit = $product->comparablePacks()->unit();
        $this->loadedUnitPriceTarget = $this->unitPriceTarget;
        $this->removeUnitPriceTarget = false;
    }

    /**
     * Reloads the per-unit field when the product moved to another unit since
     * the form loaded. The pack prices and suggestions beside it are rebuilt
     * on every render in the new unit, and a field left in the old one would
     * be stored in a unit the person no longer sees.
     */
    protected function rebaseUnitPriceTarget(?Product $product): void
    {
        if ($product === null || $this->unitPriceTargetUnit === $product->comparablePacks()->unit()) {
            return;
        }

        $this->loadUnitPriceTarget($product);
        $this->announce(__('This product now compares :unit. The target field shows it that way.', ['unit' => UnitWord::forCode($this->unitPriceTargetUnit) ?? __('per pack')]));
    }

    /**
     * A target the product can no longer compare in, as the owner set it —
     * "€0.25 per piece" — or null when the target works.
     *
     * @return array{target: string, comparesIn: ?string}|null
     */
    protected function suspendedUnitTarget(Product $product): ?array
    {
        if (! $product->isUnitTargetSuspended() || $product->unit_price_target === null) {
            return null;
        }

        return [
            'target' => MoneyFormatter::unitPrice((string) $product->unit_price_target, (string) $product->currency)
                . ' ' . (UnitWord::forCode($product->unit_price_target_unit) ?? ''),
            'comparesIn' => UnitWord::forCode($product->comparablePacks()->unit()),
        ];
    }

    public function removeUnitTarget(): void
    {
        $this->unitPriceTarget = null;
        $this->removeUnitPriceTarget = true;
        $this->announce(__('Target removed. Save changes to keep it.'));
    }

    public function keepUnitTarget(): void
    {
        $this->removeUnitPriceTarget = false;
        $this->announce(__('Target kept.'));
    }

    abstract protected function announce(string $message): void;

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
     * The per-unit target only when it was changed: the form sends every
     * field back on every save, a title edit included, and an untouched
     * target must keep the unit it was set in.
     *
     * @return array{drop_threshold_pct: ?string, drop_threshold_abs: ?string, target_price: ?string, unit_price_target?: ?string, unit_price_target_unit?: ?string}
     */
    protected function alertFieldValues(): array
    {
        $values = [
            'drop_threshold_pct' => self::blankToNull($this->dropThresholdPct),
            'drop_threshold_abs' => self::blankToNull($this->dropThresholdAbs),
            'target_price' => self::blankToNull($this->targetPrice),
        ];

        $unitTarget = self::blankToNull($this->unitPriceTarget);

        if ($this->removeUnitPriceTarget || ! self::sameTarget($unitTarget, self::blankToNull($this->loadedUnitPriceTarget))) {
            $values['unit_price_target'] = $unitTarget;
            $values['unit_price_target_unit'] = $unitTarget === null ? null : $this->unitPriceTargetUnit;
        }

        return $values;
    }

    private static function sameTarget(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return is_numeric($a) && is_numeric($b) && bccomp($a, $b, 4) === 0;
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
