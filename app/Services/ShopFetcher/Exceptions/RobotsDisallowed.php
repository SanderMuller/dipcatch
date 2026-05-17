<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

final class RobotsDisallowed extends FetchException
{
    public function code(): string
    {
        return 'robots_disallowed';
    }
}
