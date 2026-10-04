<?php declare(strict_types=1);

namespace App\Services\Poiesz;

use App\PriceAdapters\PromotionWindow;
use App\Support\DutchDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The periods of Poiesz's current offers. A product page says a product is
 * on offer, but not until when; only the offers feed behind /aanbiedingen
 * states that, per offer, with the ids of the products it covers (observed
 * 2026-10-04).
 *
 * The feed is the same for every Poiesz shop, so the periods it states are
 * cached as one small id map rather than fetched per check.
 */
final readonly class PoieszOffers
{
    public const string URL = 'https://apiv2.poiesz-supermarkten.nl/api/v2.0/offers';

    private const string CACHE_KEY = 'poiesz:offer-windows';

    private const string FAILED_KEY = 'poiesz:offer-windows:failed';

    /** Offers can be added during the week, so a read never outlives this. */
    private const int MAX_TTL_SECONDS = 6 * 3600;

    private const int RETRY_AFTER_SECONDS = 300;

    /**
     * The window the feed states for this product, or null when the feed
     * could not be read or states no single valid period for it.
     */
    public function windowFor(string $productId, ?string $label = null): ?PromotionWindow
    {
        $periods = $this->periods();
        $period = $periods[$productId] ?? null;

        // The feed's `validUntil` is the midnight after the last day: the
        // page shows 2026-10-11T00:00 as "tot en met 10 oktober".
        $startsAt = $period === null ? null : DutchDate::startOfDay($period[0]);
        $endsAt = $period === null ? null : DutchDate::startOfDay($period[1])?->subSecond();

        if ($startsAt === null || $endsAt === null) {
            return null;
        }

        return PromotionWindow::make(endsAt: $endsAt, startsAt: $startsAt, label: $label);
    }

    /**
     * @return array<string, array{0: string, 1: string}|null> product id => [validFrom, validUntil], null on a conflict
     */
    private function periods(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            /** @var array<string, array{0: string, 1: string}|null> $cached */
            return $cached;
        }

        if (Cache::has(self::FAILED_KEY)) {
            return [];
        }

        $periods = $this->fetch();

        if ($periods === null) {
            Cache::put(self::FAILED_KEY, true, self::RETRY_AFTER_SECONDS);

            return [];
        }

        Cache::put(self::CACHE_KEY, $periods, $this->ttl($periods));

        return $periods;
    }

    /**
     * @return array<string, array{0: string, 1: string}|null>|null
     */
    private function fetch(): ?array
    {
        try {
            $response = Http::timeout(5)->acceptJson()->get(self::URL);
        } catch (HttpClientException $e) {
            Log::warning('Poiesz offers fetch failed.', ['error' => $e->getMessage()]);

            return null;
        }

        $categories = $response->successful() ? $response->json('categories') : null;

        if (! is_array($categories)) {
            Log::warning('Poiesz offers response unusable.', ['status' => $response->status()]);

            return null;
        }

        $periods = [];
        $offers = 0;

        foreach ($categories as $category) {
            foreach ((array) data_get($category, 'offers', []) as $offer) {
                $offers++;
                $from = data_get($offer, 'validFrom');
                $until = data_get($offer, 'validUntil');

                if (! is_string($from) || ! is_string($until)) {
                    continue;
                }

                foreach ((array) data_get($offer, 'productIDs', []) as $id) {
                    if (! is_int($id) && ! is_string($id)) {
                        continue;
                    }

                    $key = (string) $id;
                    $periods[$key] = array_key_exists($key, $periods) && $periods[$key] !== [$from, $until]
                        ? null
                        : [$from, $until];
                }
            }
        }

        if ($offers > 0 && $periods === []) {
            Log::warning('Poiesz offers response has offers but no readable periods.', ['offers' => $offers]);
        }

        return $periods;
    }

    /**
     * Until the earliest period ends. A feed with no running period has not
     * turned over to the new week yet, so it is read again soon.
     *
     * @param  array<string, array{0: string, 1: string}|null>  $periods
     */
    private function ttl(array $periods): int
    {
        $ttl = null;
        $now = CarbonImmutable::now();

        foreach ($periods as $period) {
            $until = $period === null ? null : DutchDate::startOfDay($period[1]);

            if ($until !== null && $until->greaterThan($now)) {
                $ttl = min($ttl ?? self::MAX_TTL_SECONDS, (int) $now->diffInSeconds($until));
            }
        }

        return max(60, $ttl ?? self::RETRY_AFTER_SECONDS);
    }
}
