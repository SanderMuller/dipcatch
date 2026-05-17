<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Models\Shop;
use App\PriceAdapters\ShopSnapshot;
use App\PriceAdapters\VariantCandidate;

/**
 * Result of `ProbeShopUrl`. Mutually exclusive states: success, duplicate,
 * ambiguous (variant chooser needed), or failed (with a typed code).
 * Failures and ambiguity are non-throwing so the Livewire layer can render
 * the appropriate UI inline.
 */
final readonly class ProbeOutcome
{
    public const string STATE_SUCCESS = 'success';

    public const string STATE_DUPLICATE = 'duplicate';

    public const string STATE_FAILED = 'failed';

    public const string STATE_AMBIGUOUS = 'ambiguous';

    /**
     * @param  array<string, mixed>|null  $context   Extra info for the UI (e.g. expected/actual currency).
     * @param  list<VariantCandidate>     $variants  Populated only when state === STATE_AMBIGUOUS.
     */
    private function __construct(
        public string $state,
        public ?ShopSnapshot $snapshot = null,
        public ?string $normalizedUrl = null,
        public ?string $host = null,
        public ?string $adapterKey = null,
        public ?Shop $existingShop = null,
        public ?string $errorCode = null,
        public ?array $context = null,
        public array $variants = [],
    ) {}

    public static function success(
        ShopSnapshot $snapshot,
        string $normalizedUrl,
        string $host,
        string $adapterKey,
    ): self {
        return new self(
            state: self::STATE_SUCCESS,
            snapshot: $snapshot,
            normalizedUrl: $normalizedUrl,
            host: $host,
            adapterKey: $adapterKey,
        );
    }

    public static function duplicate(Shop $existing): self
    {
        return new self(state: self::STATE_DUPLICATE, existingShop: $existing);
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    public static function failed(string $errorCode, ?array $context = null): self
    {
        return new self(state: self::STATE_FAILED, errorCode: $errorCode, context: $context);
    }

    /**
     * @param  list<VariantCandidate>  $variants
     */
    public static function ambiguous(array $variants, string $normalizedUrl, string $host): self
    {
        return new self(
            state: self::STATE_AMBIGUOUS,
            normalizedUrl: $normalizedUrl,
            host: $host,
            variants: $variants,
        );
    }

    public function isSuccess(): bool
    {
        return $this->state === self::STATE_SUCCESS;
    }

    public function isDuplicate(): bool
    {
        return $this->state === self::STATE_DUPLICATE;
    }

    public function isFailed(): bool
    {
        return $this->state === self::STATE_FAILED;
    }

    public function isAmbiguous(): bool
    {
        return $this->state === self::STATE_AMBIGUOUS;
    }
}
