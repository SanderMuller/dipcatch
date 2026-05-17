<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

final class Blocked extends FetchException
{
    public function code(): string
    {
        return 'blocked';
    }
}
