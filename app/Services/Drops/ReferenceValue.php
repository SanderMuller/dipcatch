<?php declare(strict_types=1);

namespace App\Services\Drops;

use Spatie\LaravelData\Data;

/**
 * What a drop is measured against, and in what money.
 *
 * `$value` is the figure the percentage is computed from. It is a unit price
 * while the product has a comparison unit and `$unit` says which, and a pack
 * price otherwise. `$packValue` is always pack money, and it is null when the
 * contributing segments do not share one pack size — there is no honest money
 * figure to report then, and the absolute threshold sits the round out rather
 * than reporting a saving nobody made.
 */
final class ReferenceValue extends Data
{
    public const string KIND_MEDIAN_30D = 'median_30d';

    public const string KIND_INITIAL = 'initial';

    public function __construct(
        public string $value,
        public string $kind,
        public int $sampleSize,
        /** `g`, `ml`, `piece`, or null while this product compares packs. */
        public ?string $unit = null,
        public ?string $packValue = null,
        /** The pack size every contributing segment shared, when they did. */
        public ?float $packQuantity = null,
        public ?string $packUnit = null,
    ) {}

    public function isUnitBasis(): bool
    {
        return $this->unit !== null;
    }

    /**
     * The reference as pack money, or null when there is none to report. On the
     * pack basis that is simply the value; on the unit basis it exists only
     * while the contributing segments shared one size.
     */
    public function packBasis(): ?string
    {
        return $this->packValue ?? ($this->unit === null ? $this->value : null);
    }
}
