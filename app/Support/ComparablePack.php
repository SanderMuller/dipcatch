<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PackExclusion;
use App\Enums\PackProvenance;
use App\Models\Shop;

/**
 * One shop's answer to "how much is in this pack, in the unit this product
 * compares in?" — a size with a provenance, or an exclusion with a reason.
 *
 * Never both and never neither: a shop that cannot join the comparison carries a
 * reason a surface can print, which is what keeps it visible instead of silently
 * absent.
 */
final readonly class ComparablePack
{
    private function __construct(
        public ?PackSize $size,
        public ?PackProvenance $provenance,
        public ?PackExclusion $exclusion,
        /** The currency behind {@see PackExclusion::DifferentCurrency}. */
        public ?string $currency = null,
    ) {}

    public static function stated(PackSize $size): self
    {
        return new self($size, PackProvenance::Stated, exclusion: null);
    }

    public static function confirmed(PackSize $size): self
    {
        return new self($size, PackProvenance::Confirmed, exclusion: null);
    }

    public static function inferred(PackSize $size): self
    {
        return new self($size, PackProvenance::Inferred, exclusion: null);
    }

    /**
     * The answer for a shop the owner stated a size for, compared in `$unit`;
     * null for a shop whose page states its own size, that has no owner size,
     * or that prices in another currency.
     */
    public static function forOwnerSize(Shop $shop, string $currency, string $unit): ?self
    {
        $size = $shop->isOwnerSized() && $shop->currency === $currency ? $shop->ownerPackSize() : null;

        if (! $size instanceof PackSize) {
            return null;
        }

        return $size->unit === $unit
            ? new self($size, PackProvenance::Owner, exclusion: null)
            : self::excluded(PackExclusion::outsideTheUnit($size->unit));
    }

    /** The shop's own size in the other unit, through the item size the shops agree on. */
    public static function converted(PackSize $size): self
    {
        return new self($size, PackProvenance::Converted, exclusion: null);
    }

    public static function excluded(PackExclusion $reason, ?string $currency = null): self
    {
        return new self(size: null, provenance: null, exclusion: $reason, currency: $currency);
    }

    public function isExcluded(): bool
    {
        return $this->exclusion instanceof PackExclusion;
    }

    /**
     * Whether this size may win the per-unit ranking and be the basis of an
     * alert: a stated size, one the owner stated, or one Jev confirmed from
     * the page.
     */
    public function canWin(): bool
    {
        return in_array($this->provenance, [PackProvenance::Stated, PackProvenance::Owner, PackProvenance::Confirmed], strict: true);
    }

    /** Whether the size is this shop's own, stated by the shop or by the owner: not borrowed from or estimated through the other shops. */
    public function isOwnSize(): bool
    {
        return $this->provenance === PackProvenance::Stated || $this->provenance === PackProvenance::Owner;
    }

    /**
     * A size the shop check reads the page for (`ConfirmPackSize`): borrowed
     * from the other shops, or implausible. Not a converted size: that page
     * does state a size, and the check would find nothing to add.
     */
    public function isSizeInDoubt(): bool
    {
        return $this->provenance === PackProvenance::Inferred
            || $this->exclusion === PackExclusion::SizeImplausible;
    }

    public function reason(): ?string
    {
        return $this->exclusion?->label($this->currency);
    }

    public function unitPriceFor(mixed $price): ?string
    {
        if (! $this->size instanceof PackSize || (! is_string($price) && ! is_numeric($price))) {
            return null;
        }

        return $this->size->unitPriceFor((string) $price);
    }

    /** The unrounded figure, for any caller that compares rather than prints. */
    public function unitPriceValueFor(mixed $price): ?float
    {
        if (! $this->size instanceof PackSize || (! is_string($price) && ! is_numeric($price))) {
            return null;
        }

        return $this->size->unitPriceValueFor((string) $price);
    }
}
