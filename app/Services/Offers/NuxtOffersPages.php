<?php declare(strict_types=1);

namespace App\Services\Offers;

use App\PriceAdapters\ShopSnapshot;
use App\Support\NuxtData;
use App\Support\UpcomingDeal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The offers a Dirk or DekaMarkt shop has announced, read from its offers
 * pages. Both run one Nuxt platform: a product page states only today's
 * price record, while the offers pages list each offer with its days and
 * the products it covers (observed 2026-10-24).
 *
 * The pages are the same for every shop of a chain, so what they state is
 * cached as one product id map rather than fetched per check.
 */
abstract readonly class NuxtOffersPages
{
    /** Offers can be added during the week, so a read never outlives this. */
    private const int MAX_TTL_SECONDS = 6 * 3600;

    private const int RETRY_AFTER_SECONDS = 300;

    /** A recheck batch can miss the cache at once; the others wait this long for the one read. */
    private const int LOCK_WAIT_SECONDS = 5;

    private const int LOCK_SECONDS = 30;

    /** @return list<string> */
    abstract protected function urls(): array;

    /** Versioned, so a deploy that changes the stored shape does not read an old one. */
    abstract protected function cacheKey(): string;

    /**
     * The offers announced per product id, or null when the pages could not
     * be read: the caller then claims nothing about an upcoming offer. With
     * the switch off, nothing is announced.
     *
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: ?string}|null>|null product id => offer, null on a conflict
     */
    private function offers(): ?array
    {
        if (! Config::boolean('dipcatch.upcoming_offers.enabled')) {
            return [];
        }

        $cached = $this->cached();

        if ($cached !== null || Cache::has($this->cacheKey() . ':failed')) {
            return $cached;
        }

        try {
            Cache::lock($this->cacheKey() . ':lock', self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function (): void {
                // The caller it waited on may have read the pages, or failed to.
                if ($this->cached() === null && ! Cache::has($this->cacheKey() . ':failed')) {
                    $this->refresh();
                }
            });
        } catch (LockTimeoutException) {
            return null;
        }

        return $this->cached();
    }

    /**
     * The snapshot with the window of the offer announced for this product,
     * while today's price stays tracked. Pages that could not be read claim
     * nothing, so a stored announcement survives; a clean read without an
     * offer ends it.
     */
    public function applyTo(ShopSnapshot $snapshot, string $productId): ShopSnapshot
    {
        $offers = $this->offers();

        if ($offers === null) {
            return $snapshot->withPromotionWindow(promotionWindow: null)->withoutPromotionWindowAuthority();
        }

        $entry = $offers[$productId] ?? null;
        $announced = is_array($entry) ? AnnouncedOffer::fromArray($entry) : null;

        return $snapshot->withPromotionWindow($announced === null ? null : UpcomingDeal::window(
            startsAt: $announced->startsAt,
            endsAt: $announced->endsAt,
            offerPrice: $announced->offerPrice,
            text: $announced->text,
            todayPrice: $snapshot->price,
        ));
    }

    /**
     * The cached map as stored: an entry is turned into an offer only for the
     * product a check asks about.
     *
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: ?string}|null>|null
     */
    private function cached(): ?array
    {
        $cached = Cache::get($this->cacheKey());

        if (! is_array($cached)) {
            return null;
        }

        /** @var array<string, array{0: string, 1: string, 2: ?string, 3: ?string}|null> $cached */
        return $cached;
    }

    /** Whether the shop's `textPriceSign` is wording for a shopper, not a template id. */
    protected function readsText(): bool
    {
        return true;
    }

    /** Reads the pages into the cache; a failure leaves only the back-off marker. */
    private function refresh(): void
    {
        $offers = [];

        foreach ($this->urls() as $url) {
            $data = $this->fetch($url);

            if ($data === null) {
                Cache::put($this->cacheKey() . ':failed', true, self::RETRY_AFTER_SECONDS);

                return;
            }

            foreach (NuxtOfferRecords::parse($data, $this->readsText()) as $productId => $offer) {
                $offers[$productId] = array_key_exists($productId, $offers) && ! NuxtOfferRecords::same($offers[$productId], $offer) ? null : $offer;
            }
        }

        Cache::put(
            $this->cacheKey(),
            array_map(fn (?AnnouncedOffer $offer): ?array => $offer?->toArray(), $offers),
            self::ttl($offers),
        );
    }

    /** @return list<mixed>|null */
    private function fetch(string $url): ?array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => Config::string('dipcatch.fetcher.user_agent')])
                ->get($url);
        } catch (HttpClientException $e) {
            Log::warning('Offers page fetch failed.', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        $data = $response->successful() ? NuxtData::decode($response->body()) : null;
        $data = $data !== null && NuxtOfferRecords::listsOffers($data) ? $data : null;

        if ($data === null) {
            Log::warning('Offers page response unusable.', ['url' => $url, 'status' => $response->status()]);
        }

        return $data;
    }

    /**
     * Until the earliest announced offer starts, when its product page takes
     * over; otherwise `MAX_TTL_SECONDS`. Never under a minute.
     *
     * @param  array<string, ?AnnouncedOffer>  $offers
     */
    private static function ttl(array $offers): int
    {
        $now = CarbonImmutable::now();
        $ttl = self::MAX_TTL_SECONDS;

        foreach ($offers as $offer) {
            if ($offer !== null && $offer->startsAt->greaterThan($now)) {
                $ttl = min($ttl, (int) $now->diffInSeconds($offer->startsAt));
            }
        }

        return max(60, $ttl);
    }
}
