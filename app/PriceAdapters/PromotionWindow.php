<?php declare(strict_types=1);

namespace App\PriceAdapters;

use Carbon\CarbonImmutable;

/**
 * How long a shop says its current price runs for.
 *
 * The price is already what the app tracks; this says until when, so 1.69
 * reads as a promotion ending Sunday rather than as the new normal. Every
 * window has an end — "until when" is the whole point, so an open-ended
 * promotion is not stored.
 *
 * Distinct from {@see ConditionalOffer}, which is a price only some
 * shoppers can pay and never becomes the tracked price.
 */
final readonly class PromotionWindow
{
    public function __construct(
        public CarbonImmutable $endsAt,
        public ?CarbonImmutable $startsAt = null,
        public ?string $label = null,
    ) {}

    /**
     * The window a source stated, or null when it stated none this adapter
     * can use: no end, an unreadable date, or a start after its own end.
     */
    public static function make(?CarbonImmutable $endsAt, ?CarbonImmutable $startsAt = null, ?string $label = null): ?self
    {
        if ($endsAt === null) {
            return null;
        }

        if ($startsAt !== null && $startsAt->greaterThan($endsAt)) {
            return null;
        }

        $label = $label === null ? null : trim($label);

        return new self($endsAt, $startsAt, $label === '' ? null : $label);
    }

    public function isRunning(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($this->startsAt !== null && $now->lessThan($this->startsAt)) {
            return false;
        }

        return ! $now->greaterThan($this->endsAt);
    }

    /** Before its start — a promotion announced but not yet running. */
    public function hasNotStarted(?CarbonImmutable $now = null): bool
    {
        return $this->startsAt !== null && ($now ?? CarbonImmutable::now())->lessThan($this->startsAt);
    }

    public function hasEnded(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThan($this->endsAt);
    }
}
