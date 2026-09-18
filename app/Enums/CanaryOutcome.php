<?php declare(strict_types=1);

namespace App\Enums;

/**
 * What one canary run learned about one adapter.
 *
 * `Rot` and `BadUrl` need a person. `Unreachable` says the canary failed,
 * not the adapter — on twenty-one hosts a block or a timeout is routine.
 */
enum CanaryOutcome: string
{
    case Ok = 'ok';
    case Rot = 'rot';
    case BadUrl = 'bad_url';
    case Unreachable = 'unreachable';

    public function needsAttention(): bool
    {
        return $this === self::Rot || $this === self::BadUrl;
    }
}
