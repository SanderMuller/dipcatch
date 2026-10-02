<?php declare(strict_types=1);

namespace App\Jobs;

use App\Console\Commands\PruneOldChecksCommand;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Prunes old history one stretch of rows at a time: the drop events and
 * cheapest segments of each product, then the price checks of each shop.
 *
 * A queue job may run for 90 seconds, and the time a pass over every row
 * takes grows with the rows. So each job stops after a fixed time and queues
 * the next one at the id it reached. One try: a second delivery would start
 * a second chain, and the next night starts the pass again.
 */
#[Tries(1)]
#[Timeout(80)]
final class PruneOldHistory implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const string PRODUCTS = 'products';

    public const string SHOPS = 'shops';

    public const int WORK_SECONDS = 45;

    private const int RETAIN_MIN_PER_OFFER = 50;

    private const int RETAIN_MIN_DROP_EVENTS_PER_PRODUCT = 50;

    /**
     * @param  self::PRODUCTS|self::SHOPS  $pass
     */
    public function __construct(
        public string $pass = self::PRODUCTS,
        public ?string $afterId = null,
    ) {}

    public function uniqueId(): string
    {
        return "prune-old-history:{$this->pass}:" . ($this->afterId ?? 'start');
    }

    /**
     * Frees the lock should a queued job be lost, before the next night
     * queues the same start again.
     */
    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(): void
    {
        $cutoff = now()->subDays(PruneOldChecksCommand::RETAIN_DAYS);
        $stopAt = CarbonImmutable::now()->addSeconds(self::WORK_SECONDS);

        foreach ($this->rows() as $row) {
            if ($this->pass === self::PRODUCTS) {
                /** @var Product $row */
                $this->pruneDropEvents($row->id, $cutoff, $row->history_kept_from);
                $this->pruneCheapestHistory($row->id, $cutoff, $row->history_kept_from);
            } else {
                $this->pruneChecks($row->id, $cutoff);
            }

            if (CarbonImmutable::now()->greaterThanOrEqualTo($stopAt)) {
                dispatch(new self($this->pass, $row->id));

                return;
            }
        }

        if ($this->pass === self::PRODUCTS) {
            dispatch(new self(self::SHOPS));
        }
    }

    /**
     * @return iterable<Product|Shop>
     */
    private function rows(): iterable
    {
        $query = $this->pass === self::PRODUCTS
            ? Product::query()->select(['id', 'history_kept_from'])
            : Shop::query()->select('id');

        return $query
            ->when($this->afterId !== null, fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('id', '>', $this->afterId))
            ->lazyById(500);
    }

    /**
     * Rows dated at or after the product's `history_kept_from` are kept
     * whatever the owner's plan is tonight — that stamp is the promise that
     * a downgrade takes nothing away.
     *
     * @template TModel of Model
     *
     * @param EloquentQueryBuilder<TModel> $query
     */
    private function exceptKeptHistory(EloquentQueryBuilder $query, ?DateTimeInterface $keptFrom, string $column): void
    {
        if ($keptFrom !== null) {
            $query->where($column, '<', $keptFrom);
        }
    }

    private function pruneDropEvents(string $productId, DateTimeInterface $cutoff, ?DateTimeInterface $keptFrom): void
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

        $query->delete();
    }

    private function pruneChecks(string $offerId, DateTimeInterface $cutoff): void
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

        PriceCheck::query()
            ->where('shop_id', $offerId)
            ->where('checked_at', '<', $cutoff)
            ->whereNotIn('id', $protected)
            ->delete();
    }

    /**
     * Prune closed segments older than the cutoff. The current open segment
     * (`ended_at = null`) is never pruned so the live cheapest state is
     * always queryable.
     */
    private function pruneCheapestHistory(string $productId, DateTimeInterface $cutoff, ?DateTimeInterface $keptFrom): void
    {
        $query = ProductCheapestHistory::query()
            ->where('product_id', $productId)
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $cutoff);

        $this->exceptKeptHistory($query, $keptFrom, 'ended_at');

        $query->delete();
    }

    private static function stringify(mixed $id): string
    {
        return is_scalar($id) ? (string) $id : '';
    }
}
