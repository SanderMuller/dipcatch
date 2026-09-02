<?php declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Models\PriceCheck;
use App\Models\Shop;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ConditionalOffer;
use App\PriceAdapters\ShopSnapshot;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\RateLimitedByHost;
use App\Services\ShopFetcher\FetchResult;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\Config as DipConfig;
use App\Support\ImageUrl;
use App\Support\PackSize;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-offer price re-check. Replaces the per-product `ScrapeProductJob`.
 *
 * Fetch + parse run outside any DB transaction (network calls under lock are
 * forbidden). Persistence runs inside one transaction with fixed lock order
 * `offer → product` to prevent deadlocks against add/toggle/delete paths.
 *
 * Failure classification (see spec §5 "Per-job logic"):
 *   - success                : reset both counters
 *   - 5xx                    : increment `consecutive_5xx_failures` only
 *   - blocked / 4xx
 *     / parse_failed         : increment `consecutive_failures` only
 *   - robots_disallowed      : flip to health='dead', active=false
 *   - rate_limited           : release the job back to the queue (no counter
 *                              tick, no PriceCheck row) — see handle().
 *
 * Health transitions (config-driven):
 *   - consecutive_failures >= failing_after        → health=failing
 *   - consecutive_failures >= dead_after           → health=dead, active=false
 *   - consecutive_5xx_failures >= failing_5xx_after → health=failing
 *   - consecutive_5xx_failures >= dead_5xx_after    → health=dead, active=false
 */
class CheckShopPrice implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public Shop $shop) {}

    public function uniqueId(): string
    {
        // Including url_hash lets a manual URL change in the Filament edit_url
        // action queue an immediate recheck even when an automated recheck is
        // still holding the long uniqueness window — the new URL is a new key.
        return "check-shop:{$this->shop->id}:{$this->shop->url_hash}";
    }

    public function uniqueFor(): int
    {
        // RecheckActiveShopsCommand dispatches with up to `jitter_minutes` of
        // delay; the scheduler ticks more frequently than that, so a short
        // 60s window would let the same offer be re-queued before the original
        // delayed job has started. Hold the uniqueness lock for the full
        // jitter window plus a buffer covering job timeout + queue scheduling.
        return DipConfig::int('dipcatch.recheck.jitter_minutes', 30) * 60 + 600;
    }

    public function handle(ShopFetcher $fetcher, AdapterResolver $resolver, CheckjebonSource $checkjebon, AhApiSource $ahApi): void
    {
        $shop = Shop::query()->with('product')->find($this->shop->id);
        if ($shop === null || ! $shop->active || $shop->health === ShopHealth::Dead) {
            return;
        }

        // ah.nl: mobile API first (live, bonus-aware); dataset as fallback.
        if ($ahApi->supports($shop->host)) {
            $result = $ahApi->resolve($shop->url);
            if ($result->snapshot !== null) {
                $this->persist($shop, $this->sourceOutcome($result->snapshot, 'ah-api'));

                return;
            }
        }

        if ($checkjebon->supports($shop->host)) {
            $this->persist($shop, $this->checkjebonOutcome($shop, $checkjebon));

            return;
        }

        try {
            $outcome = $this->fetchAndExtract($shop, $fetcher, $resolver);
        } catch (RateLimitedByHost $e) {
            // Per-host budget exhausted (probe path or another worker drained
            // it). Release for retry instead of writing a `rate_limited` check
            // and ticking the failure counter — the bucket refills shortly.
            // Jitter avoids a thundering herd when many queued jobs for the
            // same drained host all wake at the bucket's exact refill instant.
            $this->release(max(1, $e->retryAfterSeconds) + random_int(0, 5));

            return;
        }

        $this->persist($shop, $outcome);
    }

    /**
     * Dataset-side: resolve from the local checkjebon dataset — no fetch, no
     * adapter chain, no per-host rate limiting (it is a local DB read). A
     * miss maps to `EmptyMatch` so a delisted product walks the existing
     * failure counters into `failing` / `dead`.
     *
     * @return array{
     *   status: ScrapeStatus,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   image_url: ?string,
     *   gtin: ?string,
     *   gtin_authoritative: bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
     *   pack_size: ?PackSize,
     *   pack_size_authoritative: bool,
     *   conditional_offer: ?ConditionalOffer,
     *   conditional_offer_authoritative: bool,
     * }
     */
    private function checkjebonOutcome(Shop $shop, CheckjebonSource $checkjebon): array
    {
        $result = $checkjebon->resolve($shop->url);
        $snapshot = $result->snapshot;

        if ($snapshot === null) {
            return [
                'status' => ScrapeStatus::EmptyMatch,
                'price' => null,
                'currency' => null,
                'in_stock' => null,
                'image_url' => null,
                'gtin' => null,
                'gtin_authoritative' => false,
                'raw' => null,
                'error' => 'checkjebon:' . $result->missReason,
                'adapter_key' => null,
                'fetch_result' => null,
                'pack_size' => null,
                'pack_size_authoritative' => false,
                'conditional_offer' => null,
                'conditional_offer_authoritative' => false,
            ];
        }

        return $this->sourceOutcome($snapshot, 'checkjebon');
    }

    /**
     * @return array{
     *   status: ScrapeStatus,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   image_url: ?string,
     *   gtin: ?string,
     *   gtin_authoritative: bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
     *   pack_size: ?PackSize,
     *   pack_size_authoritative: bool,
     *   conditional_offer: ?ConditionalOffer,
     *   conditional_offer_authoritative: bool,
     * }
     */
    private function sourceOutcome(ShopSnapshot $snapshot, string $adapterKey): array
    {
        return [
            'status' => ScrapeStatus::Ok,
            'price' => $snapshot->price,
            'currency' => $snapshot->currency,
            'in_stock' => $snapshot->inStock,
            'image_url' => ImageUrl::safe($snapshot->imageUrl),
            'gtin' => $snapshot->gtin,
            'gtin_authoritative' => $snapshot->gtinAuthoritative,
            'raw' => null,
            'error' => null,
            'adapter_key' => $adapterKey,
            'fetch_result' => null,
            'pack_size' => PackSize::resolve($snapshot->packSize, $snapshot->packSizeAuthoritative, $snapshot->title),
            'conditional_offer' => $snapshot->conditionalOffer,
            'conditional_offer_authoritative' => $snapshot->conditionalOfferAuthoritative,
            'pack_size_authoritative' => $snapshot->packSizeAuthoritative,
        ];
    }

    /**
     * Network-side: fetch HTML + run adapter chain. Returns a classified
     * outcome with everything `persist()` needs.
     *
     * @return array{
     *   status: ScrapeStatus,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   image_url: ?string,
     *   gtin: ?string,
     *   gtin_authoritative: bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
     *   pack_size: ?PackSize,
     *   pack_size_authoritative: bool,
     *   conditional_offer: ?ConditionalOffer,
     *   conditional_offer_authoritative: bool,
     * }
     */
    private function fetchAndExtract(Shop $shop, ShopFetcher $fetcher, AdapterResolver $resolver): array
    {
        try {
            $fetch = $fetcher->fetch($shop->url);
        } catch (RateLimitedByHost $e) {
            // This arm exists to PREVENT the broader FetchException catch
            // below from misclassifying rate-limit as a failed check —
            // RateLimitedByHost extends FetchException, so without this
            // specific arm it would fall into failureOutcome(). handle()
            // turns the re-thrown exception into a job release.
            throw $e;
        } catch (FetchException $e) {
            return $this->failureOutcome($e);
        } catch (Throwable $e) {
            return $this->genericFailure($e->getMessage());
        }

        $context = new AdapterContext(
            selectors: [
                'price' => $shop->price_selector,
                'title' => $shop->title_selector,
                'image' => $shop->image_selector,
            ],
            fallbackCurrency: $shop->currency,
            variantKey: $shop->variant_key,
        );

        $extraction = $resolver->resolve(
            url: $fetch->finalUrl,
            html: $fetch->html,
            persistedKey: $shop->adapter_key,
            context: $context,
        );

        if (! $extraction->isSuccess()) {
            return [
                'status' => ScrapeStatus::ParseError,
                'price' => null,
                'currency' => null,
                'in_stock' => null,
                'image_url' => null,
                'gtin' => null,
                'gtin_authoritative' => false,
                'raw' => null,
                'error' => $extraction->failureReason,
                'adapter_key' => $extraction->adapterKey,
                'fetch_result' => $fetch,
                'pack_size' => null,
                'pack_size_authoritative' => false,
                'conditional_offer' => null,
                'conditional_offer_authoritative' => false,
            ];
        }

        $snapshot = $extraction->snapshot;
        assert($snapshot !== null);

        return [
            'status' => ScrapeStatus::Ok,
            'price' => $snapshot->price,
            'currency' => $snapshot->currency,
            'in_stock' => $snapshot->inStock,
            'image_url' => ImageUrl::absolute($snapshot->imageUrl, $fetch->finalUrl),
            'gtin' => $snapshot->gtin,
            'gtin_authoritative' => $snapshot->gtinAuthoritative,
            'raw' => null,
            'error' => null,
            'adapter_key' => $extraction->adapterKey,
            'fetch_result' => $fetch,
            // Scraped adapters carry no structured size — the title is the
            // only source, and it is never authoritative.
            'pack_size' => PackSize::resolve($snapshot->packSize, $snapshot->packSizeAuthoritative, $snapshot->title),
            'conditional_offer' => $snapshot->conditionalOffer,
            'conditional_offer_authoritative' => $snapshot->conditionalOfferAuthoritative,
            'pack_size_authoritative' => $snapshot->packSizeAuthoritative,
        ];
    }

    /**
     * @return array{
     *   status: ScrapeStatus,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   image_url: ?string,
     *   gtin: ?string,
     *   gtin_authoritative: bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
     *   pack_size: ?PackSize,
     *   pack_size_authoritative: bool,
     *   conditional_offer: ?ConditionalOffer,
     *   conditional_offer_authoritative: bool,
     * }
     */
    private function failureOutcome(FetchException $e): array
    {
        // Each FetchException subclass already exposes its discriminant via
        // code() (e.g. 'blocked', 'http_error') — and they map 1:1 to
        // ScrapeStatus values. Use that mapping directly instead of
        // re-deriving with `match ($e instanceof X)`.
        $status = ScrapeStatus::tryFrom($e->code()) ?? ScrapeStatus::Failed;

        return [
            'status' => $status,
            'price' => null,
            'currency' => null,
            'in_stock' => null,
            'image_url' => null,
            'gtin' => null,
            'gtin_authoritative' => false,
            'raw' => null,
            'error' => $e->getMessage(),
            'adapter_key' => null,
            'fetch_result' => null,
            'pack_size' => null,
            'pack_size_authoritative' => false,
            'conditional_offer' => null,
            'conditional_offer_authoritative' => false,
        ];
    }

    /**
     * @return array{
     *   status: ScrapeStatus,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   image_url: ?string,
     *   gtin: ?string,
     *   gtin_authoritative: bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
     *   pack_size: ?PackSize,
     *   pack_size_authoritative: bool,
     *   conditional_offer: ?ConditionalOffer,
     *   conditional_offer_authoritative: bool,
     * }
     */
    private function genericFailure(string $message): array
    {
        return [
            'status' => ScrapeStatus::HttpError,
            'price' => null,
            'currency' => null,
            'in_stock' => null,
            'image_url' => null,
            'gtin' => null,
            'gtin_authoritative' => false,
            'raw' => null,
            'error' => $message,
            'adapter_key' => null,
            'fetch_result' => null,
            'pack_size' => null,
            'pack_size_authoritative' => false,
            'conditional_offer' => null,
            'conditional_offer_authoritative' => false,
        ];
    }

    /**
     * Persist the price_check row + offer state + invoke recompute, all under
     * one transaction with offer→product lock order.
     *
     * @param  array{
     *   status: ScrapeStatus,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   image_url: ?string,
     *   gtin: ?string,
     *   gtin_authoritative: bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
     *   pack_size: ?PackSize,
     *   pack_size_authoritative: bool,
     *   conditional_offer: ?ConditionalOffer,
     *   conditional_offer_authoritative: bool,
     * }  $outcome
     */
    private function persist(Shop $shop, array $outcome): void
    {
        $now = now();

        // One transaction spanning the price_check insert, offer state update,
        // AND the product recompute (offer → product lock order). If any step
        // fails everything rolls back together — no stale `cheapest_*` window.
        DB::transaction(function () use ($shop, $outcome, $now): void {
            $locked = Shop::query()->lockForUpdate()->find($shop->id);
            if ($locked === null) {
                return;
            }

            $status = $outcome['status'];

            $check = PriceCheck::create([
                'shop_id' => $locked->id,
                'price' => $outcome['price'],
                'currency' => $outcome['currency'],
                'in_stock' => $outcome['in_stock'],
                'status' => $status,
                'error' => $outcome['error'],
                'checked_at' => $now,
            ]);

            $updates = ['last_checked_at' => $now, 'last_status' => $status];

            if ($status === ScrapeStatus::Ok) {
                $updates += [
                    'current_price' => $outcome['price'],
                    'current_in_stock' => (bool) ($outcome['in_stock'] ?? true),
                    'currency' => $outcome['currency'] ?? $locked->currency,
                    'last_success_at' => $now,
                    'last_error' => null,
                    'consecutive_failures' => 0,
                    'consecutive_5xx_failures' => 0,
                    'health' => $locked->health === ShopHealth::Failing
                        ? ShopHealth::Ok
                        : $locked->health,
                ];

                if ($outcome['adapter_key'] !== null) {
                    $updates['adapter_key'] = $outcome['adapter_key'];
                }

                // Keep the last known image when an extraction returns none —
                // an empty picker is worse than a slightly stale thumbnail.
                if ($outcome['image_url'] !== null) {
                    $updates['image_url'] = $outcome['image_url'];
                }

                // The GTIN follows the opposite rule: an adapter that reads
                // GTIN fields and finds none is authoritative, and the stored
                // value is cleared — a mismatch warning must not outlive the
                // data it was raised on. A source with no GTIN concept (the
                // AH API, the dataset) leaves the value alone.
                if ($outcome['gtin'] !== null || $outcome['gtin_authoritative']) {
                    $updates['gtin'] = $outcome['gtin'];
                }

                // An authoritative size is written verbatim — an empty or
                // unparseable one clears the columns, because a stale unit
                // price is worse than none. A title fallback only ever fills:
                // a flaky title must not wipe a known size (spec Section 4).
                $packSize = $outcome['pack_size'];
                if ($outcome['pack_size_authoritative'] || $packSize !== null) {
                    $updates['pack_quantity'] = $packSize?->quantity;
                    $updates['pack_unit'] = $packSize?->unit;
                }

                // Same rule as the GTIN: a source that reads conditional
                // offers and finds none clears the stored one, so a campaign
                // that ended stops being shown. A source with no such concept
                // leaves it alone.
                $offer = $outcome['conditional_offer'];
                if ($offer !== null || $outcome['conditional_offer_authoritative']) {
                    $updates['conditional_price'] = $offer?->price;
                    $updates['conditional_label'] = $offer?->label;
                    $updates['conditional_starts_at'] = $offer?->startsAt;
                    $updates['conditional_ends_at'] = $offer?->endsAt;
                }
            } else {
                $updates['last_error'] = $outcome['error'];

                $counters = $this->incrementCountersFor($locked, $status);
                $updates += $counters;

                $updates += $this->healthTransitionsFor($counters);
            }

            $locked->forceFill($updates)->save();

            $locked->product?->recomputeCheapestShop((int) $check->id);
        });
    }

    /**
     * @return array{consecutive_failures?: int, consecutive_5xx_failures?: int}
     */
    private function incrementCountersFor(Shop $shop, ScrapeStatus $status): array
    {
        return match ($status) {
            ScrapeStatus::TransientServerError => [
                'consecutive_5xx_failures' => $shop->consecutive_5xx_failures + 1,
            ],
            ScrapeStatus::RobotsDisallowed => [
                // Permanent — both counters preserved but health flips to dead below.
            ],
            default => [
                'consecutive_failures' => $shop->consecutive_failures + 1,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $counters
     * @return array{health?: string, active?: bool}
     */
    private function healthTransitionsFor(array $counters): array
    {
        $failingAfter = DipConfig::int('dipcatch.shop.failing_after', 3);
        $deadAfter = DipConfig::int('dipcatch.shop.dead_after', 10);
        $failing5xx = DipConfig::int('dipcatch.shop.failing_5xx_after', 10);
        $dead5xx = DipConfig::int('dipcatch.shop.dead_5xx_after', 30);

        $main = $counters['consecutive_failures'] ?? null;
        $five = $counters['consecutive_5xx_failures'] ?? null;

        $dead = ['health' => ShopHealth::Dead->value, 'active' => false];
        $failing = ['health' => ShopHealth::Failing->value];

        // robots_disallowed: hard fail.
        if ($main === null && $five === null) {
            return $dead;
        }

        if ($main !== null) {
            if ($main >= $deadAfter) {
                return $dead;
            }
            if ($main >= $failingAfter) {
                return $failing;
            }
        }

        if ($five !== null) {
            if ($five >= $dead5xx) {
                return $dead;
            }
            if ($five >= $failing5xx) {
                return $failing;
            }
        }

        return [];
    }
}
