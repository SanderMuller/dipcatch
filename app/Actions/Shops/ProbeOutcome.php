<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ProbeFailure;
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
     * @param  array<string, mixed>|null  $context           Extra info for the UI (e.g. expected/actual currency).
     * @param  list<VariantCandidate>     $variants          Populated only when state === STATE_AMBIGUOUS.
     * @param  ?string                    $extractionReason  Layer-1 adapter diagnostic (e.g. `no_adapter_matched`,
     *                                                       `user_selector_no_match`). Populated only when
     *                                                       errorCode === ProbeFailure::ExtractionFailed.
     */
    private function __construct(
        public string $state,
        public ?ShopSnapshot $snapshot = null,
        public ?string $normalizedUrl = null,
        public ?string $host = null,
        public ?string $adapterKey = null,
        public ?Shop $existingShop = null,
        public ?ProbeFailure $errorCode = null,
        public ?array $context = null,
        public array $variants = [],
        public ?string $extractionReason = null,
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
    public static function failed(ProbeFailure $errorCode, ?array $context = null): self
    {
        return new self(state: self::STATE_FAILED, errorCode: $errorCode, context: $context);
    }

    /**
     * Failed-state factory dedicated to extraction failures — keeps the Layer-1
     * adapter reason (e.g. `no_adapter_matched`, `user_selector_no_match`)
     * alongside the typed Layer-2 ExtractionFailed code so the AddShop UI can
     * decide whether to offer the manual-selector flow.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function extractionFailed(?string $reason, ?array $context = null): self
    {
        return new self(
            state: self::STATE_FAILED,
            errorCode: ProbeFailure::ExtractionFailed,
            context: $context,
            extractionReason: $reason,
        );
    }

    /**
     * True when this outcome is a Layer-1 extraction failure that AddShop
     * should respond to by surfacing the manual-selector form instead of an
     * error message. The check is policy living next to the data so the UI
     * layer doesn't have to know Layer-1 vocabulary.
     */
    public function shouldOfferManualSelector(): bool
    {
        if ($this->errorCode !== ProbeFailure::ExtractionFailed) {
            return false;
        }

        $reason = $this->extractionReason;
        if ($reason === null) {
            return false;
        }

        return $reason === 'no_adapter_matched' || str_starts_with($reason, 'user_selector_');
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
