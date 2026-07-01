<?php declare(strict_types=1);

namespace App\Console\Commands;

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

#[Signature('dipcatch:prune-checks')]
#[Description('Prune price_checks / price_drop_events / cheapest_history older than 365 days, keeping at least 50 most-recent rows per offer/product.')]
class PruneOldChecksCommand extends Command
{
    private const int RETAIN_DAYS = 365;

    private const int RETAIN_MIN_PER_OFFER = 50;

    private const int RETAIN_MIN_DROP_EVENTS_PER_PRODUCT = 50;

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETAIN_DAYS);
        $checksDeleted = 0;
        $eventsDeleted = 0;
        $segmentsDeleted = 0;

        Product::query()
            ->select('id')
            ->lazyById(500)
            ->each(function (Product $product) use ($cutoff, &$eventsDeleted, &$segmentsDeleted): void {
                $eventsDeleted += $this->pruneDropEvents($product->id, $cutoff);
                $segmentsDeleted += $this->pruneCheapestHistory($product->id, $cutoff);
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

    private function pruneDropEvents(string $productId, DateTimeInterface $cutoff): int
    {
        $keepIds = PriceDropEvent::query()
            ->where('product_id', $productId)
            ->latest('fired_at')
            ->limit(self::RETAIN_MIN_DROP_EVENTS_PER_PRODUCT)
            ->pluck('id')
            ->all();

        $deleted = PriceDropEvent::query()
            ->where('product_id', $productId)
            ->where('fired_at', '<', $cutoff)
            ->whereNotIn('id', $keepIds)
            ->delete();

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
    private function pruneCheapestHistory(string $productId, DateTimeInterface $cutoff): int
    {
        $deleted = ProductCheapestHistory::query()
            ->where('product_id', $productId)
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $cutoff)
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    private static function stringify(mixed $id): string
    {
        return is_scalar($id) ? (string) $id : '';
    }
}
