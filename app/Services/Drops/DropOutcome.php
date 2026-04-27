<?php declare(strict_types=1);

namespace App\Services\Drops;

use Spatie\LaravelData\Data;

final class DropOutcome extends Data
{
    public function __construct(
        public bool $belowThreshold,
        public string $referencePrice,
        public string $referenceKind,
        public string $dropAbsolute,
        public string $dropPercent,
        public string $thresholdAbs,
        public string $thresholdPct,
    ) {}
}
