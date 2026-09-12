<?php declare(strict_types=1);

namespace App\Support;

final class RecheckJitter
{
    /**
     * SQS rejects a `DelaySeconds` above 900. The limit belongs to the
     * `SendMessage` call a delayed dispatch makes, so it bounds the jitter
     * window and nothing else: `release()` reschedules through
     * `ChangeMessageVisibility`, which allows up to 12 hours, so job backoff
     * arrays are unaffected.
     */
    public const int MAX_DELAY_SECONDS = 900;

    /**
     * Widest delay a recheck dispatch may carry.
     *
     * The cap applies on every queue driver, not only SQS. Local development
     * runs Redis, which has no delay ceiling, and that divergence is what let
     * a 30-minute window pass every test and then fail in production.
     */
    public static function maxSeconds(): int
    {
        $configured = Config::int('dipcatch.recheck.jitter_minutes', 30) * 60;

        return max(0, min($configured, self::MAX_DELAY_SECONDS));
    }
}
