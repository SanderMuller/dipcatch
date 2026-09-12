<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

use App\Services\ShopFetcher\Exceptions\Blocked;
use App\Services\ShopFetcher\Exceptions\TemporaryFailure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How a host has answered DipCatch lately.
 *
 * A single refusal and a standing one look identical at the call site, so
 * every one of them was reported as "try again shortly" — and a caller
 * retried a host that refuses every request, ten times over two days.
 * Counting consecutive failures per host lets the message say which of the
 * two it is. A successful fetch clears the count, so a host that recovers
 * stops being described as broken.
 *
 * The count lives in a table. It was in the cache first, and in production
 * it read zero on every call while every test passed — a shared counter
 * cannot depend on which cache store an environment happens to configure.
 */
final readonly class HostFetchMemory
{
    /** The shop refused the request: a challenge page, a 401, or a 403. */
    public const string KIND_BLOCKED = 'blocked';

    /** The shop did not answer: a connection failure or a 5xx. */
    public const string KIND_SILENT = 'silent';

    /** Consecutive failures before a host is described as persistent. */
    public const int PERSISTENT_AFTER = 3;

    /** A failure older than this says nothing about the host today. */
    private const int STALE_AFTER_SECONDS = 86_400;

    private const string TABLE = 'host_fetch_failures';

    /**
     * Record one failure and return how many consecutive ones this host has
     * now produced.
     */
    public function record(string $host, string $kind): int
    {
        $now = CarbonImmutable::now();
        $failures = $this->count($host, $kind) + 1;

        DB::table(self::TABLE)->updateOrInsert(
            ['host' => $host, 'kind' => $kind],
            ['failures' => $failures, 'last_failed_at' => $now, 'updated_at' => $now, 'created_at' => $now],
        );

        return $failures;
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
        $row = DB::table(self::TABLE)
            ->where('host', $host)
            ->where('kind', $kind)
            ->where('last_failed_at', '>=', CarbonImmutable::now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->first();

        $failures = $row->failures ?? 0;

        return is_numeric($failures) ? (int) $failures : 0;
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
        DB::table(self::TABLE)->where('host', $host)->delete();
    }
}
