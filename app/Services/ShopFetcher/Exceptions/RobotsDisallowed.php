<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

use App\Enums\ScrapeStatus;

final class RobotsDisallowed extends FetchException
{
    public function status(): ScrapeStatus
    {
        return ScrapeStatus::RobotsDisallowed;
    }
}
