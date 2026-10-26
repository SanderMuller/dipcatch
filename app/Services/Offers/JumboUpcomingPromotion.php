<?php declare(strict_types=1);

namespace App\Services\Offers;

use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\Hosts\HostUrl;
use App\PriceAdapters\ShopSnapshot;
use App\Support\DutchDate;
use App\Support\UpcomingDeal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Jumbo's promotion for one product in its next offer week. Neither the
 * product page nor /aanbiedingen shows next week, but the site's own
 * GraphQL endpoint answers per SKU for a given date, without an account
 * (observed 2026-10-24). Every Jumbo promotion starts on a Wednesday at
 * 00:01, so one question at Wednesday noon covers the week.
 *
 * `price(referenceDate:)` is what the site's own bundle asks; the
 * `referenceDate` argument of `promotions` is not, and was found from an
 * error message, so this is the less stable of the two.
 */
final readonly class JumboUpcomingPromotion
{
    public const string URL = 'https://www.jumbo.com/api/graphql';

    private const string QUERY = 'query DipCatchUpcoming($sku: String!, $day: String, $moment: String) {'
        . ' product(sku: $sku) { price(referenceDate: $day) { price promoPrice }'
        . ' promotions(referenceDate: $moment) { start { iso } end { iso } tags { text } } } }';

    private const int TTL_SECONDS = 6 * 3600;

    private const int RETRY_AFTER_SECONDS = 300;

    private const string FAILED_KEY = 'jumbo:upcoming:v1:failed';

    private const int LOCK_WAIT_SECONDS = 5;

    private const int LOCK_SECONDS = 30;

    /**
     * The announced promotion and next week's regular price, or null when
     * Jumbo could not be asked: the caller then claims nothing.
     *
     * @return array{offer: ?AnnouncedOffer, regularPrice: ?string}|null
     */
    private function lookup(string $sku): ?array
    {
        if (! Config::boolean('dipcatch.upcoming_offers.enabled')) {
            return ['offer' => null, 'regularPrice' => null];
        }

        $moment = self::referenceMoment();
        $key = 'jumbo:upcoming:v1:' . $sku . ':' . $moment->format('Y-m-d');
        $cached = self::cached($key);

        if ($cached !== null || Cache::has(self::FAILED_KEY)) {
            return $cached;
        }

        try {
            Cache::lock($key . ':lock', self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($sku, $moment, $key): void {
                // The caller it waited on may have asked, or failed to.
                if (self::cached($key) === null && ! Cache::has(self::FAILED_KEY)) {
                    self::ask($sku, $moment, $key);
                }
            });
        } catch (LockTimeoutException) {
            return null;
        }

        return self::cached($key);
    }

    /**
     * With no promotion running on the page: the one Jumbo announced for
     * next week, while today's price stays tracked. A multi-buy is read
     * against next week's regular price. When Jumbo cannot be asked, only
     * the window loses authority: without bundle authority the check keeps
     * the stored prices over the price the page just stated.
     */
    public function applyTo(ShopSnapshot $snapshot, string $url): ShopSnapshot
    {
        $sku = self::sku($url);

        if ($sku === null) {
            return $snapshot;
        }

        $answer = $this->lookup($sku);

        // A JSON-LD window that stated nothing running must not be written
        // over a stored announcement either.
        if ($answer === null) {
            return $snapshot->withPromotionWindow(promotionWindow: null)->withoutPromotionWindowAuthority();
        }

        $announced = $answer['offer'];
        $window = $announced === null ? null : UpcomingDeal::window(
            startsAt: $announced->startsAt,
            endsAt: $announced->endsAt,
            offerPrice: $announced->offerPrice,
            text: $announced->text,
            todayPrice: $snapshot->price,
        );

        if ($window === null) {
            return $snapshot;
        }

        $bundle = $announced->text !== null && $answer['regularPrice'] !== null
            ? BundleOffer::fromLabel($announced->text, $answer['regularPrice'])
            : null;

        return $snapshot->withPromotionWindow($window)->withBundleOffer($bundle);
    }

    /** `/producten/pitloze-witte-druiven-500-g-306009BAK` → `306009BAK`. */
    private static function sku(string $url): ?string
    {
        $segment = HostUrl::lastSegment($url);

        return $segment !== null && preg_match('/-(\d+[A-Za-z]+)$/', $segment, $match) === 1 ? strtoupper($match[1]) : null;
    }

    /** Wednesday 12:00 in Amsterdam, after today: the middle of the first day of the next offer week. */
    public static function referenceMoment(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone(DutchDate::ZONE)->next(CarbonImmutable::WEDNESDAY)->setTime(12, 0);
    }

    /** @return array{offer: ?AnnouncedOffer, regularPrice: ?string}|null */
    private static function cached(string $key): ?array
    {
        $cached = Cache::get($key);

        if (! is_array($cached)) {
            return null;
        }

        /** @var array{offer: array{0: string, 1: string, 2: ?string, 3: ?string}|null, regularPrice: ?string} $cached */
        return ['offer' => $cached['offer'] === null ? null : AnnouncedOffer::fromArray($cached['offer']), 'regularPrice' => $cached['regularPrice']];
    }

    /** Asks Jumbo and caches the answer; a failure leaves only the back-off marker. */
    private static function ask(string $sku, CarbonImmutable $moment, string $key): void
    {
        $variables = [
            'sku' => $sku,
            // `price` takes a date; `promotions` a datetime with an offset. A
            // malformed date is answered as "no promotion", not as an error.
            'day' => $moment->format('Y-m-d'),
            'moment' => $moment->toIso8601String(),
        ];

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => Config::string('dipcatch.fetcher.user_agent'),
                    'Accept' => 'application/json',
                    // Without these the endpoint answers 400 CSRF_ERROR or 401.
                    'apollo-require-preflight' => 'true',
                    'apollographql-client-name' => 'JUMBO_WEB',
                    'apollographql-client-version' => 'dipcatch',
                ])
                ->get(self::URL, ['query' => self::QUERY, 'variables' => json_encode($variables, JSON_THROW_ON_ERROR)]);
        } catch (HttpClientException $e) {
            self::failed(['sku' => $sku, 'error' => $e->getMessage()]);

            return;
        }

        if (! $response->successful() || $response->json('errors') !== null || ! is_array($response->json('data'))) {
            self::failed(['sku' => $sku, 'status' => $response->status()]);

            return;
        }

        $answer = self::answer($response->json('data.product'));
        Cache::put($key, ['offer' => $answer['offer']?->toArray(), 'regularPrice' => $answer['regularPrice']], self::TTL_SECONDS);
    }

    /**
     * The earliest promotion that has not started. One that runs now can
     * also run on next Wednesday (a two-week promotion), and is no
     * announcement.
     *
     * @return array{offer: ?AnnouncedOffer, regularPrice: ?string}
     */
    private static function answer(mixed $product): array
    {
        $promoPrice = data_get($product, 'price.promoPrice');
        $regularPrice = data_get($product, 'price.price');
        $offers = [];

        foreach ((array) data_get($product, 'promotions', []) as $promotion) {
            $start = data_get($promotion, 'start.iso');
            $end = data_get($promotion, 'end.iso');
            $text = data_get($promotion, 'tags.0.text');

            $startsAt = DutchDate::startOfDay($start);
            $endsAt = DutchDate::endOfDay($end);

            if ($startsAt !== null && $endsAt !== null && $startsAt->isFuture()) {
                $offers[] = new AnnouncedOffer(
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    offerPrice: is_int($promoPrice) ? self::euros($promoPrice) : null,
                    text: is_string($text) ? $text : null,
                );
            }
        }

        usort($offers, fn (AnnouncedOffer $a, AnnouncedOffer $b): int => $a->startsAt <=> $b->startsAt);

        return ['offer' => $offers[0] ?? null, 'regularPrice' => is_int($regularPrice) ? self::euros($regularPrice) : null];
    }

    private static function euros(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }

    /** @param  array<string, mixed>  $context */
    private static function failed(array $context): void
    {
        Log::warning('Jumbo upcoming promotion lookup failed.', $context);
        Cache::put(self::FAILED_KEY, true, self::RETRY_AFTER_SECONDS);
    }
}
