<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\HttpError;
use RuntimeException;
use Throwable;

/** Thrown by {@see CappedStream} the moment a response grows past the cap. */
final class BodyTooLarge extends RuntimeException
{
    /**
     * A 413 when the cap stopped this request, otherwise `$otherwise`. Guzzle
     * and Laravel each wrap the cause once, so it is found by walking the chain.
     */
    public static function or(Throwable $e, FetchException $otherwise): FetchException
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof self) {
                return new HttpError(413);
            }
        }

        return $otherwise;
    }
}
