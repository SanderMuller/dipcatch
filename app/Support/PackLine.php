<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PackProvenance;
use App\Models\Shop;

/**
 * What one shop charges and for how much: `€21.99 for 800 pieces`, with the
 * deal terms when the price is a bundle's.
 *
 * The size is for the description only. It is the resolved size when the shop
 * takes part in the comparison, else the size the shop's own page states, else
 * none. No unit price is ever computed from it here.
 */
final readonly class PackLine
{
    private function __construct(
        public Shop $shop,
        public ?string $price,
        public ?PackSize $size,
        public bool $estimated,
        /** `2 for €6.00 · or €3.50 each` while a bundle sets the price. */
        public ?string $bundle,
        /** A size the page does not state, which Jev confirmed from it. */
        public bool $checkedByAi = false,
        /**
         * The other figure, while the shops sell one item in different counts
         * ({@see ItemPricing}): `€5.75 each` beside a price per litre, or
         * `€16.42 /l` where the price per item leads.
         */
        public ?string $aside = null,
        /** `4 × 350 ml` where the price per item leads, else the size as a shopper names it. */
        public ?string $sizeText = null,
    ) {}

    public static function of(Shop $shop, ?ComparablePacks $packs = null): self
    {
        $pack = $packs?->for($shop);
        $size = $pack->size ?? $shop->packSize();
        $items = $packs?->items();
        $leads = $packs?->leadsWithItems() === true;

        return new self(
            shop: $shop,
            price: $shop->current_price === null ? null : (string) $shop->current_price,
            size: $size,
            estimated: in_array($pack?->provenance, [PackProvenance::Inferred, PackProvenance::Converted], strict: true),
            bundle: BundlePriceLabel::forShop($shop),
            checkedByAi: $pack?->provenance === PackProvenance::Confirmed,
            aside: $leads ? self::perUnit($shop, $packs) : self::each($shop, $items?->countFor($shop)),
            sizeText: $size === null ? null : ($leads && $items !== null ? $items->sizeLabel($shop, $size) : UnitWord::pack($size)),
        );
    }

    /** `€16.42 /l`, beside a price per item. */
    private static function perUnit(Shop $shop, ?ComparablePacks $packs): ?string
    {
        $unitPrice = $packs?->unitPriceOf($shop);

        return $unitPrice === null ? null : MoneyFormatter::unitPrice($unitPrice, $shop->currency) . ' ' . UnitWord::labelFor($packs?->unit());
    }

    /**
     * Not for a single item, whose pack price says it already, and not beside
     * a bundle, whose own "or €3.50 each" would read as the same figure.
     */
    private static function each(Shop $shop, ?float $count): ?string
    {
        if ($count === null || $count <= 1 || $shop->current_price === null || BundlePriceLabel::forShop($shop) !== null) {
            return null;
        }

        $price = number_format((float) $shop->current_price / $count, MoneyFormatter::decimals($shop->currency), '.', '');

        return __(':price each', ['price' => MoneyFormatter::format($price, $shop->currency)]);
    }

    /** `€21.99 for 800 pieces`, `€21.99 for 800 pieces (estimated)`, or `€21.99`. */
    public function text(): string
    {
        $text = $this->priceAndSize();

        if ($this->estimated) {
            $text .= ' (' . __('estimated') . ')';
        }

        if ($this->checkedByAi) {
            $text .= ' (' . __('size checked by AI') . ')';
        }

        if ($this->aside !== null) {
            $text .= ' · ' . $this->aside;
        }

        return $this->bundle === null ? $text : $text . ' · ' . $this->bundle;
    }

    /** `€22.99 for 4 × 350 ml`, `€2.75 for 227 g`, or the price alone. */
    public function priceAndSize(): string
    {
        $money = MoneyFormatter::format($this->price, $this->shop->currency);

        return $this->sizeText === null ? $money : __(':price for :pack', ['price' => $money, 'pack' => $this->sizeText]);
    }

    /** `€2.75 for 227 g`, or the price alone without a size — for a figure stored away from its shop. */
    public static function format(?string $price, string $currency, ?PackSize $size): string
    {
        $money = MoneyFormatter::format($price, $currency);

        return $size === null ? $money : __(':price for :pack', ['price' => $money, 'pack' => UnitWord::pack($size)]);
    }

    /**
     * `€12.70 for 5 pieces`: the pack a unit price comes to, rounded down to
     * the shown decimals so a pack at that price is always at or under the
     * unit price.
     *
     * @param  numeric-string  $unitPrice
     */
    public static function forUnitPrice(string $unitPrice, string $currency, PackSize $size): string
    {
        // A unit price is per kg or per litre, so grams and millilitres count in thousands.
        $quantity = bcdiv(number_format($size->quantity, 6, '.', ''), $size->unit === 'piece' ? '1' : '1000', 9);

        return self::format(bcmul($unitPrice, $quantity, MoneyFormatter::decimals($currency)), $currency, $size);
    }
}
