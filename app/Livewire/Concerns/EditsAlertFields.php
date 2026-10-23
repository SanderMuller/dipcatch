<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Shops\SaveOwnerPackSize;
use App\Models\Product;
use App\Models\Shop;
use App\Support\MoneyFormatter;
use App\Support\Numeric;
use App\Support\ShopNames;
use App\Support\UnitTargetConversion;
use App\Support\UnitTargetGuide;
use App\Support\UnitTargetMath;
use App\Support\UnitWord;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;

/**
 * The alert the edit page and the add-product wizard share: one target, per
 * kilo, litre or piece while a shop can win per unit, or the price of one
 * shop's pack while none can.
 *
 * @phpstan-type PackChoice array{shopId: string, host: string, shop: string, pack: string, perPack: float, price: ?float, unitPrice: ?float, ownerSize: ?string, shopSays: ?string}
 */
trait EditsAlertFields
{
    public ?string $unitPriceTarget = null;

    /**
     * The unit the target field is in: the product's comparison unit when the
     * form loaded, or `pack` while no shop could win per unit. A changed
     * target is stored in it, so a price check that moves the product to
     * another unit while the form is open does not change what the typed
     * number means.
     */
    #[Locked]
    public ?string $unitPriceTargetUnit = null;

    /** The shop of the pack chosen under "Which pack do you buy?". A `pack` target is pinned to it. */
    public ?string $chosenPackShopId = null;

    /** The per-unit field as it loaded. Saving it unchanged leaves the owner's target alone. */
    #[Locked]
    public ?string $loadedUnitPriceTarget = null;

    #[Locked]
    public ?string $loadedPackShopId = null;

    /** Set by "Remove target" on a target the field cannot show. */
    public bool $removeUnitPriceTarget = false;

    /** Which helper sets the price on the "My own price" row: `price`, `percent` or `amount`. */
    public string $alertHelper = 'price';

    public ?string $alertHelperValue = null;

    /** "How much is in this pack?" — the size the owner states for the chosen shop. */
    public ?string $packSizeInput = null;

    /**
     * The target in the unit the form works in: today's comparison unit, the
     * one every helper beside the field reads, or `pack` while no shop can
     * win per unit. A target that cannot be expressed in it loads empty; the
     * page names it beside the field instead.
     */
    protected function loadUnitPriceTarget(Product $product): void
    {
        $unit = self::formUnit($product);
        $sameKind = ($product->unit_price_target_unit === UnitTargetConversion::PACK) === ($unit === UnitTargetConversion::PACK);
        $effective = $sameKind ? $product->effectiveUnitPriceTarget() : null;

        // The column keeps four decimals, which the form would show as 7.0000.
        $this->unitPriceTarget = $effective === null ? null : Numeric::trimmed($effective);
        $this->unitPriceTargetUnit = $unit;
        $this->chosenPackShopId = $unit === UnitTargetConversion::PACK && $effective !== null ? $product->unit_price_target_shop_id : null;
        $this->loadedUnitPriceTarget = $this->unitPriceTarget;
        $this->loadedPackShopId = $this->chosenPackShopId;
        $this->removeUnitPriceTarget = false;
        $this->alertHelper = 'price';
        $this->alertHelperValue = null;
    }

    private static function formUnit(Product $product): ?string
    {
        return $product->bestValueShop() === null ? UnitTargetConversion::PACK : $product->comparablePacks()->unit();
    }

    /**
     * Reloads the target field when the product moved to another unit since
     * the form loaded. The pack prices and suggestions beside it are rebuilt
     * on every render in the new unit, and a field left in the old one would
     * be stored in a unit the person no longer sees.
     */
    protected function rebaseUnitPriceTarget(?Product $product): void
    {
        if ($product === null || $this->unitPriceTargetUnit === self::formUnit($product)) {
            return;
        }

        $this->loadUnitPriceTarget($product);
        $this->announce(__('This product now compares :unit. The target field shows it that way.', ['unit' => UnitWord::forCode($this->unitPriceTargetUnit) ?? __('per pack')]));
    }

    /**
     * A target the form cannot show in the unit it works in — "€0.25 per
     * piece", "€22.80 for the pack at AH" — with why, or null when the field
     * shows it.
     *
     * @return array{target: string, comparesIn: ?string, pack: bool, waitsForSize: bool}|null
     */
    protected function suspendedUnitTarget(Product $product): ?array
    {
        if ($product->unit_price_target === null || $this->loadedUnitPriceTarget !== null) {
            return null;
        }

        $pack = $product->unit_price_target_unit === UnitTargetConversion::PACK;
        $shop = $pack ? $product->shops->first(fn (Shop $shop): bool => $shop->id === $product->unit_price_target_shop_id) : null;

        return [
            'target' => $pack
                ? __(':price for the pack at :shop', [
                    'price' => MoneyFormatter::format((string) $product->unit_price_target, (string) $product->currency),
                    'shop' => $shop === null ? __('a removed shop') : ShopNames::label((string) $shop->host),
                ])
                : MoneyFormatter::unitPrice((string) $product->unit_price_target, (string) $product->currency) . ' ' . (UnitWord::forCode($product->unit_price_target_unit) ?? ''),
            'comparesIn' => UnitWord::forCode($product->comparablePacks()->unit()),
            'pack' => $pack,
            // Still working: a pack target on a product that compares per
            // unit, waiting for its own shop to say how much is in the pack.
            'waitsForSize' => $pack && ! $product->isUnitTargetSuspended(),
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

    abstract protected function alertProduct(): Product;

    /**
     * Saves the size the owner states for one shop's pack, now rather than on
     * the form's save: it can give the product a price per unit, and turn a
     * target per pack into one, so the form loads the target again.
     */
    public function saveOwnerPackSize(SaveOwnerPackSize $save, string $shopId): void
    {
        $product = $this->alertProduct();
        $this->authorize('update', $product);
        $this->resetErrorBag('packSizeInput');

        $save($product, $shopId, $this->packSizeInput, 'packSizeInput');

        $this->packSizeInput = null;
        $this->loadUnitPriceTarget($product->refresh());
        $this->chosenPackShopId ??= $shopId;
        $this->announce(__('Size saved.'));
        $this->dispatch('alert-size-saved');
    }

    public function removeOwnerPackSize(SaveOwnerPackSize $save, string $shopId): void
    {
        $product = $this->alertProduct();
        $this->authorize('update', $product);
        $this->resetErrorBag('packSizeInput');

        $save($product, $shopId, null, 'packSizeInput');

        $this->packSizeInput = null;
        $this->loadUnitPriceTarget($product->refresh());
        $this->chosenPackShopId ??= $shopId;
        $this->announce(__('Your size removed.'));
        $this->dispatch('alert-size-saved');
    }

    /**
     * @return array<string, FluentRuleContract>
     */
    protected function alertFieldRules(): array
    {
        return [
            // A cent is a floor for money and a ceiling for a rate: a tablet
            // costs three hundredths of one, and a target above every real
            // value cannot be set at all.
            'unitPriceTarget' => FluentRule::numeric('Target price')->nullable()->min(0.0001),
            'chosenPackShopId' => FluentRule::string('Pack')->nullable(),
            'alertHelper' => FluentRule::string('Helper')->required()->in(['price', 'percent', 'amount']),
            'alertHelperValue' => FluentRule::numeric('My own price')->nullable()->min(0.01),
        ];
    }

    /**
     * Readies the target after validation: a `pack` target needs a shop of
     * this product a shopper can buy from, and a percentage or an amount is
     * worked out again on the server. The price helper needs nothing.
     *
     * @throws ValidationException
     */
    protected function prepareAlert(Product $product): void
    {
        // An untouched target is not checked: its shop may have sold out
        // since the page loaded, and a title edit must still save.
        if (self::blankToNull($this->alertHelperValue) === null && $this->alertFieldValues() === []) {
            return;
        }

        [$chosen, $best] = $this->chosenPack($product);
        $value = self::blankToNull($this->alertHelperValue);

        if ($this->alertHelper === 'price' || $value === null || $chosen === null || $best === null) {
            return;
        }

        $this->unitPriceTarget = Numeric::trimmed($this->helperTarget($chosen, $best, $value));
    }

    /**
     * The chosen pack and today's best one, as the form shows them. No pick
     * yet is the first pack. A pick that is not one of the packs is refused
     * where it counts: for a `pack` target, and for a helper worked out on it.
     *
     * @return array{0: PackChoice|null, 1: PackChoice|null}
     *
     * @throws ValidationException
     */
    private function chosenPack(Product $product): array
    {
        $guide = new UnitTargetGuide($product);
        $pack = $this->unitPriceTargetUnit === UnitTargetConversion::PACK;
        $choices = $pack ? $guide->pinnablePacks() : $guide->packs();
        $chosen = array_find($choices, fn (array $choice): bool => $choice['shopId'] === $this->chosenPackShopId);
        $refused = $this->chosenPackShopId !== null && $chosen === null && ($pack || self::blankToNull($this->alertHelperValue) !== null);
        $chosen ??= $choices[0] ?? null;

        if ($refused || ($pack && $chosen === null && self::blankToNull($this->unitPriceTarget) !== null)) {
            throw ValidationException::withMessages(['chosenPackShopId' => __('Pick the pack you buy.')]);
        }

        if ($pack) {
            $this->chosenPackShopId = $chosen['shopId'] ?? null;
        }

        // Today's best: the first pack per unit, or the chosen pack while no
        // shop can be compared per unit.
        return [$chosen, $pack ? $chosen : ($choices[0] ?? null)];
    }

    /**
     * @param  PackChoice  $chosen
     * @param  PackChoice  $best
     *
     * @throws ValidationException
     */
    private function helperTarget(array $chosen, array $best, string $value): string
    {
        if ($this->alertHelper === 'percent') {
            $target = $best['price'] === null ? null : UnitTargetMath::percentUnderPack(UnitTargetMath::decimal($best['price']), $value, UnitTargetMath::decimal($best['perPack']));

            return $target ?? throw ValidationException::withMessages(['alertHelperValue' => __('Enter a percentage between 0 and 100.')]);
        }

        $target = $chosen['price'] === null ? null : UnitTargetMath::amountUnder(UnitTargetMath::decimal($chosen['price']), $value, UnitTargetMath::decimal($chosen['perPack']));

        return $target ?? throw ValidationException::withMessages(['alertHelperValue' => __('Enter less than today’s price for your :pack.', ['pack' => $chosen['pack']])]);
    }

    /**
     * The columns the target writes, an empty field as null: the target, its
     * unit, and for a `pack` target the shop it is pinned to.
     *
     * Only when it was changed: the form sends every field back on every
     * save, a title edit included, and an untouched target must keep the
     * unit it was set in.
     *
     * @return array{unit_price_target?: ?string, unit_price_target_unit?: ?string, unit_price_target_shop_id?: ?string}
     */
    protected function alertFieldValues(): array
    {
        $unitTarget = self::blankToNull($this->unitPriceTarget);
        $pack = $this->unitPriceTargetUnit === UnitTargetConversion::PACK;
        $shopChanged = $pack && $unitTarget !== null && $this->chosenPackShopId !== $this->loadedPackShopId;

        if (! $this->removeUnitPriceTarget && ! $shopChanged && self::sameTarget($unitTarget, self::blankToNull($this->loadedUnitPriceTarget))) {
            return [];
        }

        return [
            'unit_price_target' => $unitTarget,
            'unit_price_target_unit' => $unitTarget === null ? null : $this->unitPriceTargetUnit,
            'unit_price_target_shop_id' => $unitTarget !== null && $pack ? $this->chosenPackShopId : null,
        ];
    }

    private static function sameTarget(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return is_numeric($a) && is_numeric($b) && bccomp($a, $b, 4) === 0;
    }

    private static function blankToNull(?string $value): ?string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed === '' ? null : $trimmed;
    }
}
