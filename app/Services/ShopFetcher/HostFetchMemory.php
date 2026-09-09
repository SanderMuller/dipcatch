<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

use App\Services\ShopFetcher\Exceptions\Blocked;
use App\Services\ShopFetcher\Exceptions\TemporaryFailure;
use Illuminate\Support\Facades\Cache;

/**
 * How a host has answered DipCatch lately.
 *
 * A single refusal and a standing one look identical at the call site, so
 * every one of them was reported as "try again shortly" — and a caller
 * retried a host that refuses every request, four times over ten minutes.
 * Counting consecutive failures per host lets the message say which of the
 * two it is. A successful fetch clears the count, so a host that recovers
 * stops being described as broken.
 */
final readonly class HostFetchMemory
{
    /** The shop refused the request: a challenge page, a 401, or a 403. */
    public const string KIND_BLOCKED = 'blocked';

    /** The shop did not answer: a connection failure or a 5xx. */
    public const string KIND_SILENT = 'silent';

    /** Consecutive failures before a host is described as persistent. */
    public const int PERSISTENT_AFTER = 3;

    private const int TTL_SECONDS = 86_400;

    /**
     * Record one failure and return how many consecutive ones this host has
     * now produced.
     */
    public function record(string $host, string $kind): int
    {
        $count = $this->count($host, $kind) + 1;

        Cache::put(self::key($host, $kind), $count, self::TTL_SECONDS);

        return $count;
    }

    /**
     * Record the failure a fetch threw, under the kind it belongs to.
     */
    public function recordFailure(string $host, Blocked|TemporaryFailure $failure): void
    {
        $this->record($host, $failure instanceof Blocked ? self::KIND_BLOCKED : self::KIND_SILENT);
    }

    public function count(string $host, string $kind): int
    {
        $count = Cache::get(self::key($host, $kind));

        return is_int($count) ? $count : 0;
    }

    /**
     * True once this host has failed the same way often enough that another
     * attempt is not worth a caller's time.
     */
    public function isPersistent(string $host, string $kind): bool
    {
        return $this->count($host, $kind) >= self::PERSISTENT_AFTER;
    }

    /** One host answered, so nothing it did before still describes it. */
    public function forget(string $host): void
    {
        Cache::forget(self::key($host, self::KIND_BLOCKED));
        Cache::forget(self::key($host, self::KIND_SILENT));
    }

    private static function key(string $host, string $kind): string
    {
        return "dipcatch:fetcher:memory:{$kind}:{$host}";
    }
}
