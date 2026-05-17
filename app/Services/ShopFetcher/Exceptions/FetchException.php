<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

use RuntimeException;

abstract class FetchException extends RuntimeException
{
    /**
     * Stable code matching `price_checks.status` / offer `last_status`.
     */
    abstract public function code(): string;
}
