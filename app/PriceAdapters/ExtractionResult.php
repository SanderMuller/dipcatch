<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Four-state extraction outcome per spec §2:
 *
 *  - skip      → adapter does not apply to this URL/HTML; caller tries next.
 *  - failed    → adapter matched markers but couldn't extract; caller STOPS
 *                and surfaces the failure (no fallback to weaker adapters).
 *  - success   → use the snapshot.
 *  - ambiguous → page has multiple variants and the adapter can't pick one
 *                from URL alone. Caller surfaces the variant chooser.
 */
final readonly class ExtractionResult
{
    public const string STATE_SKIP = 'skip';

    public const string STATE_FAILED = 'failed';

    public const string STATE_SUCCESS = 'success';

    public const string STATE_AMBIGUOUS = 'ambiguous';

    /**
     * @param  list<VariantCandidate>  $variants
     */
    private function __construct(
        public string $state,
        public ?ShopSnapshot $snapshot,
        public ?string $failureReason,
        public ?string $adapterKey = null,
        public array $variants = [],
    ) {}

    public static function skip(): self
    {
        return new self(self::STATE_SKIP, snapshot: null, failureReason: null);
    }

    public static function failed(string $reason): self
    {
        return new self(self::STATE_FAILED, snapshot: null, failureReason: $reason);
    }

    public static function success(ShopSnapshot $snapshot): self
    {
        return new self(self::STATE_SUCCESS, $snapshot, failureReason: null);
    }

    /**
     * @param  list<VariantCandidate>  $variants
     */
    public static function ambiguous(array $variants): self
    {
        return new self(self::STATE_AMBIGUOUS, snapshot: null, failureReason: 'multiple_variants', variants: $variants);
    }

    public function withAdapterKey(string $key): self
    {
        return new self($this->state, $this->snapshot, $this->failureReason, $key, $this->variants);
    }

    public function isSkip(): bool
    {
        return $this->state === self::STATE_SKIP;
    }

    public function isFailed(): bool
    {
        return $this->state === self::STATE_FAILED;
    }

    public function isSuccess(): bool
    {
        return $this->state === self::STATE_SUCCESS;
    }

    public function isAmbiguous(): bool
    {
        return $this->state === self::STATE_AMBIGUOUS;
    }
}
