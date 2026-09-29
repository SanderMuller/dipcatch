<?php declare(strict_types=1);

namespace App\Services\Drops;

use Spatie\LaravelData\Data;

/**
 * One drop, carrying both bases explicitly.
 *
 * The percentage is computed per unit whenever the product has a comparison
 * unit, because that is the only honest way to compare two different pack
 * sizes. The money stays pack money, because that is what the shopper saves —
 * and it is null when the reference and the current winner sell different
 * amounts, where no money figure describes anything.
 */
final class DropOutcome extends Data
{
    public function __construct(
        public bool $belowThreshold,
        public bool $needsConfirmation,
        public ?string $referencePrice,
        public string $referenceKind,
        public ?string $dropAbsolute,
        public string $dropPercent,
        public ?string $thresholdAbs,
        public ?string $thresholdPct,
        public ?string $referenceUnitPrice = null,
        public ?string $newUnitPrice = null,
        public ?string $comparisonUnit = null,
    ) {}
}
