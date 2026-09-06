<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\ProUsers;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Signature('dipcatch:prune-checks')]
#[Description('Prune price_checks / price_drop_events / cheapest_history older than 365 days, keeping at least 50 most-recent rows per offer/product.')]
class PruneOldChecksCommand extends Command
{
    private const int RETAIN_DAYS = 365;

    private const int RETAIN_MIN_PER_OFFER = 50;

    private const int RETAIN_MIN_DROP_EVENTS_PER_PRODUCT = 50;

    public function handle(): int
    {
        $this->stampKeptHistory();

        $cutoff = now()->subDays(self::RETAIN_DAYS);
        $checksDeleted = 0;
        $eventsDeleted = 0;
        $segmentsDeleted = 0;

        Product::query()
            ->select(['id', 'history_kept_from'])
            ->lazyById(500)
            ->each(function (Product $product) use ($cutoff, &$eventsDeleted, &$segmentsDeleted): void {
                $keptFrom = $product->history_kept_from;
                $eventsDeleted += $this->pruneDropEvents($product->id, $cutoff, $keptFrom);
                $segmentsDeleted += $this->pruneCheapestHistory($product->id, $cutoff, $keptFrom);
            });

        Shop::query()
            ->select('id')
            ->lazyById(500)
            ->each(function (Shop $shop) use ($cutoff, &$checksDeleted): void {
                $checksDeleted += $this->pruneChecks($shop->id, $cutoff);
            });

        $this->info("Pruned {$checksDeleted} price_checks, {$eventsDeleted} price_drop_events, {$segmentsDeleted} cheapest_history segments.");

        return self::SUCCESS;
    }

    /**
     * Marks the point from which an entitled account's history is kept for
     * good. One statement over the `ProUsers::ids()` subquery rather than a
     * plan lookup per product, and `history_kept_from IS NULL` makes it
     * write-once: cancelling stops new products being stamped, it never
     * retracts a stamp already there.
     *
     * The stamp is the product's own `created_at`, not the moment it was
     * written. Stamping "now" would protect nothing that already exists,
     * so a subscriber's first year would still be pruned away as it aged
     * past the cutoff — the opposite of what they are paying for.
     */
    private function stampKeptHistory(): void
    {
        Product::query()
            ->whereNull('history_kept_from')
            ->whereIn('user_id', ProUsers::ids())
            ->update(['history_kept_from' => DB::raw('created_at')]);
    }

    /**
     * Rows dated at or after the product's `history_kept_from` are kept
     * whatever the owner's plan is tonight — that stamp is the promise that
     * a downgrade takes nothing away.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function exceptKeptHistory(Builder $query, ?DateTimeInterface $keptFrom, string $column): void
    {
        if ($keptFrom !== null) {
            $query->where($column, '<', $keptFrom);
        }
    }

    private function pruneDropEvents(string $productId, DateTimeInterface $cutoff, ?DateTimeInterface $keptFrom): int
    {
        $keepIds = PriceDropEvent::query()
            ->where('product_id', $productId)
            ->latest('fired_at')
            ->limit(self::RETAIN_MIN_DROP_EVENTS_PER_PRODUCT)
            ->pluck('id')
            ->all();

        $query = PriceDropEvent::query()
            ->where('product_id', $productId)
            ->where('fired_at', '<', $cutoff)
            ->whereNotIn('id', $keepIds);

        $this->exceptKeptHistory($query, $keptFrom, 'fired_at');

        $deleted = $query->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    private function pruneChecks(string $offerId, DateTimeInterface $cutoff): int
    {
        $keepCheckIds = PriceCheck::query()
            ->where('shop_id', $offerId)
            ->latest('checked_at')
            ->limit(self::RETAIN_MIN_PER_OFFER)
            ->pluck('id')
            ->all();

        // Checks referenced by any surviving drop event must stay. Match both:
        //   - new events whose triggered_by_shop_id points at this offer
        //   - legacy events (triggered_by_shop_id NULL) whose price_check
        //     belongs to this offer — without this branch upgraded databases
        //     leave pre-refactor events dangling at NULL price_check_id rows.
        $referencedByEvents = PriceDropEvent::query()
            ->whereNotNull('price_check_id')
            ->where(function (EloquentBuilder $q) use ($offerId): void {
                $q->whereHas('triggeredByShop', function (EloquentBuilder $inner) use ($offerId): void {
                    $inner->where('shops.id', $offerId);
                })->orWhereHas('priceCheck', function (EloquentBuilder $inner) use ($offerId): void {
                    $inner->where('price_checks.shop_id', $offerId);
                });
            })
            ->pluck('price_check_id')
            ->all();

        $protected = array_values(array_unique([
            ...array_map(self::stringify(...), $keepCheckIds),
            ...array_map(self::stringify(...), $referencedByEvents),
        ]));

        $deleted = PriceCheck::query()
            ->where('shop_id', $offerId)
            ->where('checked_at', '<', $cutoff)
            ->whereNotIn('id', $protected)
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Prune closed segments older than the cutoff. The current open segment
     * (`ended_at = null`) is never pruned so the live cheapest state is
     * always queryable.
     */
    private function pruneCheapestHistory(string $productId, DateTimeInterface $cutoff, ?DateTimeInterface $keptFrom): int
    {
        $query = ProductCheapestHistory::query()
            ->where('product_id', $productId)
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $cutoff);

        $this->exceptKeptHistory($query, $keptFrom, 'ended_at');

        $deleted = $query->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    private static function stringify(mixed $id): string
    {
        return is_scalar($id) ? (string) $id : '';
    }
}
