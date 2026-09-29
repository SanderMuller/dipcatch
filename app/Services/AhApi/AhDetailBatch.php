<?php declare(strict_types=1);

namespace App\Services\AhApi;

use App\Services\Checkjebon\CheckjebonResult;
use Closure;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Asks Albert Heijn about many products at once.
 *
 * Separate from {@see AhApiSource} only to keep the plumbing out of it: that
 * class reads one answer and decides what it means, and this one gets several
 * answers into its hands. A price check still asks about one product; a caller
 * that wants dozens — the demo seeder wants sixty-eight — would otherwise pay a
 * round trip each, which was sixteen seconds of a twenty-four-second seed.
 */
final readonly class AhDetailBatch
{
    private const string DETAIL_URL = 'https://api.ah.nl/mobile-services/product/detail/v4/fir/%s';

    private const int TIMEOUT_SECONDS = 15;

    /**
     * One result per URL asked about, in the order they were asked.
     *
     * A URL naming no product, and a request that never came back at all,
     * both answer with a miss: the caller has nothing to read either way.
     *
     * @param  list<string>  $urls
     * @param  Closure(string): ?string  $productId  the id a URL names, if any
     * @param  Closure(string, Response): CheckjebonResult  $read  what one response means
     * @return array<string, CheckjebonResult>
     */
    public static function resolve(string $token, array $urls, string $userAgent, string $application, Closure $productId, Closure $read): array
    {
        $wanted = [];

        foreach ($urls as $url) {
            $id = $productId($url);

            if ($id !== null) {
                $wanted[$url] = $id;
            }
        }

        $responses = self::fetch($token, array_values(array_unique($wanted)), $userAgent, $application);
        $results = [];

        foreach ($urls as $url) {
            $id = $wanted[$url] ?? null;
            $response = $id === null ? null : ($responses[$id] ?? null);

            $results[$url] = $response instanceof Response
                ? $read($id ?? '', $response)
                : CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        return $results;
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, Response>
     */
    private static function fetch(string $token, array $productIds, string $userAgent, string $application): array
    {
        if ($productIds === []) {
            return [];
        }

        $responses = Http::pool(function (Pool $pool) use ($token, $productIds, $userAgent, $application): array {
            $requests = [];

            foreach ($productIds as $id) {
                $requests[] = $pool->as($id)
                    ->withToken($token)
                    ->withHeaders(['User-Agent' => $userAgent, 'X-Application' => $application])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->get(sprintf(self::DETAIL_URL, $id));
            }

            return $requests;
        });

        $answers = [];

        foreach ($productIds as $id) {
            if (($responses[$id] ?? null) instanceof Response) {
                $answers[$id] = $responses[$id];
            }
        }

        return $answers;
    }
}
