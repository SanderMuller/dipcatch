<?php declare(strict_types=1);

namespace App\Services\Drops;

use Spatie\LaravelData\Data;

final class ReferenceValue extends Data
{
    public const string KIND_MEDIAN_30D = 'median_30d';

    public const string KIND_INITIAL = 'initial';

    public function __construct(
        public string $value,
        public string $kind,
        public int $sampleSize,
    ) {}
}
