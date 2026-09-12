<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

use App\Support\UnservableShops;

/**
 * The page came from a host whose prices are never in the served HTML. It
 * is the same class of dead end as a JS-rendered page, so it reports as
 * `needs_js` — see {@see UnservableShops}.
 */
final class NotServable extends FetchException
{
    public function __construct(
        public readonly string $host,
        public readonly string $reason,
    ) {
        parent::__construct("{$host} renders its prices in the browser ({$reason}).");
    }

    public function code(): string
    {
        return 'needs_js';
    }
}
