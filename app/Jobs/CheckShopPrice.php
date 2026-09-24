<?php declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Drops\DetectTargetPrice;
use App\Actions\Drops\DetectUnitPriceTarget;
use App\Actions\Shops\CheckOutcome;
use App\Actions\Shops\ResolvedBundlePricing;
use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Models\PriceCheck;
use App\Models\Shop;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ShopSnapshot;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\RateLimitedByHost;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\Config as DipConfig;
use App\Support\ImageUrl;
use App\Support\Iso4217;
use App\Support\MovedShopUrl;
use App\Support\PackSize;
use App\Support\RecheckJitter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
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
 *                              tick, no PriceCheck row) until the release
 *                              budget runs out, then recorded like any other
 *                              failure — see handle().
 *
 * Health transitions (config-driven):
 *   - consecutive_failures >= failing_after        → health=failing
 *   - consecutive_failures >= dead_after           → health=dead, active=false
 *   - consecutive_5xx_failures >= failing_5xx_after → health=failing
 *   - consecutive_5xx_failures >= dead_5xx_after    → health=dead, active=false
 */
#[MaxExceptions(1)]
#[Timeout(30)]
#[Tries(10)]
final class CheckShopPrice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Releases the rate-limit branch makes before it gives up and records the
     * throttling. Four waits of up to a minute outlast a drained bucket, which
     * refills within one.
     */
    private const int MAX_RATE_LIMIT_ATTEMPTS = 5;

    /**
     * Longest a release waits. An upstream `Retry-After` is whatever the shop
     * says, so without a ceiling one 429 asking for an hour would keep the job
     * alive past uniqueFor() and let a duplicate be dispatched alongside it.
     */
    private const int MAX_RELEASE_DELAY_SECONDS = 60;

    /**
     * Lock-key scope per origin. A scope rather than a flag in the key so no
     * two origins share a lock. `confirmation` is the re-fetch a large drop
     * asks for: without its own scope the scheduled check's key would swallow
     * it, because that key is held for a full jitter window plus a buffer.
     */
    private const string SCOPE_AUTO = 'auto';

    private const string SCOPE_MANUAL = 'manual';

    private const string SCOPE_CONFIRMATION = 'confirmation';

    public function __construct(public Shop $shop, public bool $manual = false, public bool $confirmation = false) {}

    public function uniqueId(): string
    {
        // Including url_hash keeps a recheck of a repointed offer out of the
        // long uniqueness window an automated recheck may still hold — the new
        // URL is a new key.
        //
        // A person-initiated run is keyed apart from the automated one. It
        // takes no lock, because a sync dispatch never acquires one, but
        // `CallQueuedHandler` force-releases the key when the job finishes.
        // On a shared key that unlocks the offer's queued recheck, and the
        // next scheduler tick queues a second check for the same offer.
        $scope = match (true) {
            $this->confirmation => self::SCOPE_CONFIRMATION,
            $this->manual => self::SCOPE_MANUAL,
            default => self::SCOPE_AUTO,
        };

        return "check-shop:{$this->shop->id}:{$this->shop->url_hash}:{$scope}";
    }

    public function uniqueFor(): int
    {
        // RecheckActiveShopsCommand dispatches with up to one jitter window of
        // delay; the scheduler ticks more frequently than that, so a short
        // 60s window would let the same offer be re-queued before the original
        // delayed job has started. Hold the uniqueness lock for the full
        // jitter window plus a buffer covering job timeout + queue scheduling.
        // Read the window through RecheckJitter so the lock cannot outlive or
        // undercut the delay the command actually draws.
        return RecheckJitter::maxSeconds() + 600;
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
            // it). Wait for the bucket to refill rather than charging the
            // offer for our own throttle. The jitter avoids a thundering herd
            // when many jobs for one drained host wake at the same instant.
            $delay = min(max(1, $e->retryAfterSeconds), self::MAX_RELEASE_DELAY_SECONDS) + random_int(0, 5);

            // attempts() counts this run, and is 0 outside a queue context —
            // where release() is a no-op and there is nothing to bound.
            if ($this->attempts() < self::MAX_RATE_LIMIT_ATTEMPTS) {
                $this->release($delay);

                return;
            }

            // The host stayed saturated across the whole budget. Record the
            // throttling rather than losing the cycle silently.
            $outcome = CheckOutcome::failure($e->status(), $e->getMessage());
        }

        $this->persist($shop, $outcome);
    }

    /**
     * Dataset-side: resolve from the local checkjebon dataset — no fetch, no
     * adapter chain, no per-host rate limiting (it is a local DB read). A
     * miss maps to `EmptyMatch` so a delisted product walks the existing
     * failure counters into `failing` / `dead`.
     */
    private function checkjebonOutcome(Shop $shop, CheckjebonSource $checkjebon): CheckOutcome
    {
        $result = $checkjebon->resolve($shop->url);
        $snapshot = $result->snapshot;

        if ($snapshot === null) {
            return CheckOutcome::failure(ScrapeStatus::EmptyMatch, 'checkjebon:' . $result->missReason);
        }

        return $this->sourceOutcome($snapshot, 'checkjebon');
    }

    /**
     * A source that hands back a snapshot without fetching a page: its image
     * URL is already absolute, so there is no page to resolve it against.
     */
    private function sourceOutcome(ShopSnapshot $snapshot, string $adapterKey): CheckOutcome
    {
        return CheckOutcome::success($snapshot, $adapterKey, ImageUrl::safe($snapshot->imageUrl));
    }

    /**
     * Network-side: fetch HTML + run adapter chain. Returns a classified
     * outcome with everything `persist()` needs.
     */
    private function fetchAndExtract(Shop $shop, ShopFetcher $fetcher, AdapterResolver $resolver): CheckOutcome
    {
        try {
            $fetch = $fetcher->fetch($shop->url);
        } catch (RateLimitedByHost $e) {
            // This arm exists to PREVENT the broader FetchException catch
            // below from misclassifying rate-limit as a failed check —
            // RateLimitedByHost extends FetchException, so without this
            // specific arm it would fall into the failure outcome below.
            // handle() turns the re-thrown exception into a job release.
            throw $e;
        } catch (FetchException $e) {
            return CheckOutcome::failure($e->status(), $e->getMessage());
        } catch (Throwable $e) {
            return CheckOutcome::failure(ScrapeStatus::HttpError, $e->getMessage());
        }

        $context = new AdapterContext(
            selectors: [
                'price' => $shop->price_selector,
                'title' => $shop->title_selector,
                'image' => $shop->image_selector,
            ],
            fallbackCurrency: $shop->currency,
            variantKey: $shop->variant_key,
            // A shop saved without a variant keeps reading the page's default
            // rather than start failing: see ShopifyAdapter.
            acceptPageDefault: true,
        );

        $extraction = $resolver->resolve(
            url: $fetch->finalUrl,
            html: $fetch->html,
            persistedKey: $shop->adapter_key,
            context: $context,
        );

        if (! $extraction->isSuccess()) {
            return CheckOutcome::failure(ScrapeStatus::ParseError, $extraction->failureReason);
        }

        $snapshot = $extraction->snapshot;
        assert($snapshot !== null);

        return CheckOutcome::success(
            $snapshot,
            $extraction->adapterKey,
            ImageUrl::absolute($snapshot->imageUrl, $fetch->finalUrl),
            movedTo: $fetch->movedPermanently ? $fetch->finalUrl : null,
            movedFrom: $shop->url,
        );
    }

    /**
     * Persist the price_check row + offer state + invoke recompute, all under
     * one transaction with offer→product lock order.
     */
    private function persist(Shop $shop, CheckOutcome $outcome): void
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

            $status = $outcome->status;
            $snapshot = $outcome->snapshot;

            $reported = strtoupper(trim($snapshot->currency ?? ''));
            $expected = strtoupper(trim($locked->currency));

            $status = self::afterCurrencyCheck($status, $reported, $expected);

            // The column is char(3). A shop quoting " EUR " or "Euro" is a
            // shop stating no code we can store, and the mismatch check
            // above has already read the raw value.
            $storedCurrency = Iso4217::normalize($reported);

            // Only a success carries a snapshot. Deciding that once keeps the
            // offer row and the price_checks row on the same side: read
            // separately, an Ok without a snapshot would write a successful
            // check beside a shop update that counted a failure.
            $succeeded = $status === ScrapeStatus::Ok && $snapshot !== null;

            $updates = ['last_checked_at' => $now, 'last_status' => $status];
            $pricing = ResolvedBundlePricing::merge(
                shop: $locked,
                singleItemPrice: $snapshot?->price,
                offer: $snapshot?->bundleOffer,
                offerAuthoritative: $snapshot->bundleOfferAuthoritative ?? false,
                reportedWindow: $snapshot?->promotionWindow,
                windowAuthoritative: $snapshot->promotionWindowAuthoritative ?? false,
            );

            if ($succeeded) {
                $updates += $pricing->shopUpdates() + [
                    // Unknown stays unknown: coercing it to true is what
                    // reported a sold-out product as available.
                    'current_in_stock' => $snapshot->inStock,
                    // Re-read on every successful check, so a shop that opens
                    // to the public, or starts quoting ex-VAT, joins or leaves
                    // the comparison on its own.
                    'consumer_price_issue' => $snapshot->consumerPriceIssue,
                    'consumer_price_note' => $snapshot->consumerPriceNote,
                    // An empty currency is no signal at all — keep the last known one
                    // rather than blanking the column.
                    'currency' => $storedCurrency ?? $locked->currency,
                    'last_success_at' => $now,
                    'last_error' => null,
                    'consecutive_failures' => 0,
                    'consecutive_5xx_failures' => 0,
                    'health' => $locked->health === ShopHealth::Failing
                        ? ShopHealth::Ok
                        : $locked->health,
                ];

                if ($outcome->adapterKey !== null) {
                    $updates['adapter_key'] = $outcome->adapterKey;
                }

                // Store where the page moved for good, so the next check
                // asks once instead of following the redirect every time.
                $updates += MovedShopUrl::updates($locked, $outcome->movedFrom, $outcome->movedTo);

                // Keep the last known image when an extraction returns none —
                // an empty picker is worse than a slightly stale thumbnail.
                if ($outcome->imageUrl !== null) {
                    $updates['image_url'] = $outcome->imageUrl;
                }

                // The GTIN follows the opposite rule: an adapter that reads
                // GTIN fields and finds none is authoritative, and the stored
                // value is cleared — a mismatch warning must not outlive the
                // data it was raised on. A source with no GTIN concept (the
                // AH API, the dataset) leaves the value alone.
                if ($snapshot->gtin !== null || $snapshot->gtinAuthoritative) {
                    $updates['gtin'] = $snapshot->gtin;
                }

                // An authoritative size is written verbatim — an empty or
                // unparseable one clears the columns, because a stale unit
                // price is worse than none. A title fallback only ever fills:
                // a flaky title must not wipe a known size (spec Section 4).
                $packSize = PackSize::resolve($snapshot->packSize, $snapshot->packSizeAuthoritative, $snapshot->title);
                if ($snapshot->packSizeAuthoritative || $packSize !== null) {
                    $updates['pack_quantity'] = $packSize?->quantity;
                    $updates['pack_unit'] = $packSize?->unit;
                }

                $updates += $pricing->promotionUpdates;

                // Same rule as the GTIN: a source that reads conditional
                // offers and finds none clears the stored one, so a campaign
                // that ended stops being shown. A source with no such concept
                // leaves it alone.
                $offer = $snapshot->conditionalOffer;
                if ($offer !== null || $snapshot->conditionalOfferAuthoritative) {
                    $updates['conditional_price'] = $offer?->price;
                    $updates['conditional_label'] = $offer?->label;
                    $updates['conditional_starts_at'] = $offer?->startsAt?->utc();
                    $updates['conditional_ends_at'] = $offer?->endsAt?->utc();
                }
            } else {
                $updates['last_error'] = $outcome->error;
                $updates += ResolvedBundlePricing::expiredFailureUpdates($locked);

                $counters = $this->incrementCountersFor($locked, $status);
                $updates += $counters;

                $updates += $this->healthTransitionsFor($counters);
            }

            $check = PriceCheck::create($pricing->priceCheckAttributes(
                $succeeded,
                $snapshot?->price,
            ) + [
                'shop_id' => $locked->id,
                'currency' => $storedCurrency,
                'in_stock' => $snapshot?->inStock,
                'status' => $status,
                'error' => $outcome->error,
                'checked_at' => $now,
            ]);

            $locked->forceFill($updates)->save();

            $locked->product?->recomputeCheapestShop((int) $check->id);

            // Separate from the drop engine on purpose: a rival shop cutting
            // its price changes the best value without changing which shop is
            // cheapest, so the cheapest-price trigger would never see it.
            $product = $locked->product;

            if ($product !== null) {
                $product->refresh();

                app(DetectUnitPriceTarget::class)($product);
                app(DetectTargetPrice::class)($product);
            }
        });
    }

    /**
     * A shop that starts quoting another currency is not a cheaper shop. The
     * add path already refuses this (ProbeShopUrl, ProbeFailure::CurrencyMismatch);
     * without the same check here the offer competes numerically against the
     * others and fires a drop alert for a price that does not exist.
     *
     * An empty reported currency is no signal at all, not a mismatch — some
     * adapters legitimately return none.
     */
    private static function afterCurrencyCheck(ScrapeStatus $status, string $reported, string $expected): ScrapeStatus
    {
        if ($status !== ScrapeStatus::Ok || $reported === '' || $reported === $expected) {
            return $status;
        }

        return ScrapeStatus::CurrencyMismatch;
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
