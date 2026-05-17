<?php declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Models\PriceCheck;
use App\Models\Shop;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\Services\ShopFetcher\Exceptions\Blocked;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\HttpError;
use App\Services\ShopFetcher\Exceptions\RateLimitedByHost;
use App\Services\ShopFetcher\Exceptions\RobotsDisallowed;
use App\Services\ShopFetcher\Exceptions\TemporaryFailure;
use App\Services\ShopFetcher\FetchResult;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\Config as DipConfig;
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

    public function handle(ShopFetcher $fetcher, AdapterResolver $resolver): void
    {
        $shop = Shop::query()->with('product')->find($this->shop->id);
        if ($shop === null || ! $shop->active || $shop->health === ShopHealth::Dead) {
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
     * Network-side: fetch HTML + run adapter chain. Returns a classified
     * outcome with everything `persist()` needs.
     *
     * @return array{
     *   status: string,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
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
                'status' => ScrapeStatus::ParseError->value,
                'price' => null,
                'currency' => null,
                'in_stock' => null,
                'raw' => null,
                'error' => $extraction->failureReason,
                'adapter_key' => $extraction->adapterKey,
                'fetch_result' => $fetch,
            ];
        }

        $snapshot = $extraction->snapshot;
        assert($snapshot !== null);

        return [
            'status' => ScrapeStatus::Ok->value,
            'price' => $snapshot->price,
            'currency' => $snapshot->currency,
            'in_stock' => $snapshot->inStock,
            'raw' => null,
            'error' => null,
            'adapter_key' => $extraction->adapterKey,
            'fetch_result' => $fetch,
        ];
    }

    /**
     * @return array{
     *   status: string,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
     * }
     */
    private function failureOutcome(FetchException $e): array
    {
        $status = match (true) {
            $e instanceof RobotsDisallowed => ScrapeStatus::RobotsDisallowed,
            $e instanceof Blocked => ScrapeStatus::Blocked,
            $e instanceof TemporaryFailure => ScrapeStatus::TransientServerError,
            $e instanceof HttpError => ScrapeStatus::HttpError,
            default => ScrapeStatus::Failed,
        };

        return [
            'status' => $status->value,
            'price' => null,
            'currency' => null,
            'in_stock' => null,
            'raw' => null,
            'error' => $e->getMessage(),
            'adapter_key' => null,
            'fetch_result' => null,
        ];
    }

    /**
     * @return array{
     *   status: string,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
     * }
     */
    private function genericFailure(string $message): array
    {
        return [
            'status' => ScrapeStatus::HttpError->value,
            'price' => null,
            'currency' => null,
            'in_stock' => null,
            'raw' => null,
            'error' => $message,
            'adapter_key' => null,
            'fetch_result' => null,
        ];
    }

    /**
     * Persist the price_check row + offer state + invoke recompute, all under
     * one transaction with offer→product lock order.
     *
     * @param  array{
     *   status: string,
     *   price: ?string,
     *   currency: ?string,
     *   in_stock: ?bool,
     *   raw: ?string,
     *   error: ?string,
     *   adapter_key: ?string,
     *   fetch_result: ?FetchResult,
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

            $check = PriceCheck::create([
                'shop_id' => $locked->id,
                'price' => $outcome['price'],
                'currency' => $outcome['currency'],
                'in_stock' => $outcome['in_stock'],
                'status' => $outcome['status'],
                'error' => $outcome['error'],
                'checked_at' => $now,
            ]);

            $updates = ['last_checked_at' => $now, 'last_status' => $outcome['status']];

            if ($outcome['status'] === ScrapeStatus::Ok->value) {
                $updates += [
                    'current_price' => $outcome['price'],
                    'current_in_stock' => (bool) ($outcome['in_stock'] ?? true),
                    'currency' => $outcome['currency'] ?? $locked->currency,
                    'last_success_at' => $now,
                    'last_error' => null,
                    'consecutive_failures' => 0,
                    'consecutive_5xx_failures' => 0,
                    'health' => $locked->health === ShopHealth::Failing
                        ? ShopHealth::Ok->value
                        : $locked->health->value,
                ];

                if ($outcome['adapter_key'] !== null) {
                    $updates['adapter_key'] = $outcome['adapter_key'];
                }
            } else {
                $updates['last_error'] = $outcome['error'];

                $counters = $this->incrementCountersFor($locked, $outcome['status']);
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
    private function incrementCountersFor(Shop $shop, string $status): array
    {
        return match ($status) {
            ScrapeStatus::TransientServerError->value => [
                'consecutive_5xx_failures' => $shop->consecutive_5xx_failures + 1,
            ],
            ScrapeStatus::RobotsDisallowed->value => [
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
