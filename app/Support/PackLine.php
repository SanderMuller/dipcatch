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
    ) {}

    public static function of(Shop $shop, ?ComparablePacks $packs = null): self
    {
        $pack = $packs?->for($shop);

        return new self(
            shop: $shop,
            price: $shop->current_price === null ? null : (string) $shop->current_price,
            size: $pack->size ?? $shop->packSize(),
            estimated: $pack?->provenance === PackProvenance::Inferred,
            bundle: BundlePriceLabel::forShop($shop),
        );
    }

    /** `€21.99 for 800 pieces`, `€21.99 for 800 pieces (estimated)`, or `€21.99`. */
    public function text(): string
    {
        $text = self::format($this->price, $this->shop->currency, $this->size);

        if ($this->estimated) {
            $text .= ' (' . __('estimated') . ')';
        }

        return $this->bundle === null ? $text : $text . ' · ' . $this->bundle;
    }

    /** `€2.75 for 227 g`, or the price alone without a size — for a figure stored away from its shop. */
    public static function format(?string $price, string $currency, ?PackSize $size): string
    {
        $money = MoneyFormatter::format($price, $currency);

        return $size === null ? $money : __(':price for :pack', ['price' => $money, 'pack' => UnitWord::pack($size)]);
    }
}
