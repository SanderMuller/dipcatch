<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

/**
 * Non-5xx, non-block HTTP failure (404, 410, etc.).
 */
final class HttpError extends FetchException
{
    public function __construct(public int $statusCode)
    {
        parent::__construct("HTTP {$statusCode}.");
    }

    public function code(): string
    {
        return 'http_error';
    }
}
