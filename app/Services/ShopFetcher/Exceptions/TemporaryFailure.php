<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

/**
 * Transient server errors (5xx). Health-counted separately from the main
 * failure counter — see `offers.consecutive_5xx_failures` and the per-job
 * algorithm in spec §5.
 */
final class TemporaryFailure extends FetchException
{
    public function __construct(public int $statusCode)
    {
        parent::__construct("Upstream HTTP {$statusCode}.");
    }

    public function code(): string
    {
        return '5xx';
    }
}
