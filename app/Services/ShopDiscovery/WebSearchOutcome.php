<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Models\WebSearch;

/**
 * A search, or why there is none. The Klarna steps tell a spent daily limit
 * or a busy lock, which wait for the next run, from a provider failure, which
 * counts against their attempts.
 */
final readonly class WebSearchOutcome
{
    public const string FOUND = 'found';

    public const string QUOTA_SPENT = 'quota_spent';

    public const string LOCK_BUSY = 'lock_busy';

    public const string FAILED = 'failed';

    /**
     * @param  bool  $searchedNow  This call ran the search and stored it; false for a stored search, a waiter's one included.
     */
    private function __construct(public string $reason, public ?WebSearch $search = null, public bool $searchedNow = false) {}

    public static function found(WebSearch $search): self
    {
        return new self(self::FOUND, $search);
    }

    public static function searchedNow(WebSearch $search): self
    {
        return new self(self::FOUND, $search, searchedNow: true);
    }

    public static function quotaSpent(): self
    {
        return new self(self::QUOTA_SPENT);
    }

    public static function lockBusy(): self
    {
        return new self(self::LOCK_BUSY);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    /** Waits for the next run without counting an attempt. */
    public function isDeferred(): bool
    {
        return $this->reason === self::QUOTA_SPENT || $this->reason === self::LOCK_BUSY;
    }
}
