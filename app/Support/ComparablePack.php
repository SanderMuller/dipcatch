<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PackExclusion;
use App\Enums\PackProvenance;

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

    public static function inferred(PackSize $size): self
    {
        return new self($size, PackProvenance::Inferred, exclusion: null);
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
     * alert. Only a stated size may.
     */
    public function canWin(): bool
    {
        return $this->provenance === PackProvenance::Stated;
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
}
