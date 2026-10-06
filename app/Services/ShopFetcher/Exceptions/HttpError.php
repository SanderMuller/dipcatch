<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

use App\Enums\ScrapeStatus;

/**
 * Non-5xx, non-block HTTP failure (404, 410, etc.).
 *
 * A `$reason` replaces the default message where the status code stands for
 * an answer the shop gave another way, such as a redirect away from a page
 * that is gone.
 */
final class HttpError extends FetchException
{
    public function __construct(public int $statusCode, ?string $reason = null)
    {
        parent::__construct($reason ?? "HTTP {$statusCode}.");
    }

    public function status(): ScrapeStatus
    {
        return ScrapeStatus::HttpError;
    }
}
