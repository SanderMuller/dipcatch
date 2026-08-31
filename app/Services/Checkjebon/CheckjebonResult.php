<?php declare(strict_types=1);

namespace App\Services\Checkjebon;

use App\PriceAdapters\ShopSnapshot;

/**
 * Result of {@see CheckjebonSource::resolve()}. Either a snapshot built
 * from the local dataset row, or a miss with a machine-readable reason
 * (`not_in_dataset` | `unrecognized_url` | `dataset_empty`) the UI turns
 * into copy.
 */
final readonly class CheckjebonResult
{
    public const string REASON_NOT_IN_DATASET = 'not_in_dataset';

    public const string REASON_UNRECOGNIZED_URL = 'unrecognized_url';

    public const string REASON_DATASET_EMPTY = 'dataset_empty';

    public const string REASON_API_ERROR = 'api_error';

    private function __construct(
        public ?ShopSnapshot $snapshot,
        public ?string $missReason,
    ) {}

    public static function found(ShopSnapshot $snapshot): self
    {
        return new self(snapshot: $snapshot, missReason: null);
    }

    public static function miss(string $reason): self
    {
        return new self(snapshot: null, missReason: $reason);
    }

    public function isFound(): bool
    {
        return $this->snapshot instanceof ShopSnapshot;
    }
}
