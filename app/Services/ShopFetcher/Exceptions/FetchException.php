<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

use App\Enums\ScrapeStatus;
use RuntimeException;

abstract class FetchException extends RuntimeException
{
    abstract public function status(): ScrapeStatus;
}
