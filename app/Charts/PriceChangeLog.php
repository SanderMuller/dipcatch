<?php declare(strict_types=1);

namespace App\Charts;

use App\Enums\LargeDropCheckOutcome;
use App\Models\LargeDropCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\TargetPriceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * The changes of a product's lowest price, newest first, each with what
 * DipCatch did about it: the list under the price chart.
 *
 * Built from the "Lowest price" segments. A segment that moved only the best
 * value, the pack size or a bundle is not a price change: it is skipped, and
 * the change before it runs on through it.
 */
final readonly class PriceChangeLog
{
    private const int RECHECK_GIVES_UP_AFTER_HOURS = 24;

    public function __construct(private Product $product, private ?CarbonImmutable $windowStart) {}

    /**
     * @return list<array{at: CarbonImmutable, shop: ?string, from: ?string, to: ?string, changePct: ?int, action: ?PriceChangeAction}>
     */
    public function rows(): array
    {
        $changes = $this->changes();

        $triggers = array_values(array_filter(array_map(static fn (array $change): ?int => $change['segment']->triggering_price_check_id, $changes)));
        $checks = LargeDropCheck::query()->whereIn('price_check_id', $triggers)->get()->keyBy('price_check_id');
        $answers = $checks->pluck('resolved_by_price_check_id')->filter()->values()->all();
        $alerted = PriceDropEvent::query()->whereIn('price_check_id', [...$triggers, ...$answers])->pluck('price_check_id')->flip();
        $reached = TargetPriceEvent::query()
            ->where('product_id', $this->product->id)
            ->when($this->windowStart, fn (Builder $query, CarbonImmutable $start): Builder => $query->where('fired_at', '>=', $start))
            ->get(['shop_id', 'fired_at']);

        $rows = [];

        foreach ($changes as $change) {
            $segment = $change['segment'];

            if ($this->windowStart !== null && $segment->started_at->isBefore($this->windowStart)) {
                continue;
            }

            $trigger = $segment->triggering_price_check_id;
            $check = $trigger === null ? null : $checks->get($trigger);
            $wasAlerted = ($trigger !== null && $alerted->has($trigger))
                || ($check?->resolved_by_price_check_id !== null && $alerted->has($check->resolved_by_price_check_id));

            $rows[] = [
                'at' => $segment->started_at,
                'shop' => $segment->hostOf($segment->cheapestShop),
                'from' => $change['from'],
                'to' => $segment->cheapest_price,
                'changePct' => self::changePct($change['from'], $segment->cheapest_price),
                'action' => self::actionFor($check, $wasAlerted, self::reachedIn($reached, $segment, $change['until'])),
            ];
        }

        return array_reverse($rows);
    }

    /**
     * Each change of the lowest price or its shop, in order, with the price
     * before it and the moment the next change took over.
     *
     * @return list<array{segment: ProductCheapestHistory, from: ?string, until: ?CarbonImmutable}>
     */
    private function changes(): array
    {
        $segments = $this->product->cheapestHistory()
            ->inOrder()
            ->overlapping($this->windowStart)
            ->with('cheapestShop')
            ->get();

        $starts = [];
        $previous = null;

        foreach ($segments as $segment) {
            if ($previous === null || $segment->cheapest_price !== $previous->cheapest_price || $segment->cheapest_shop_id !== $previous->cheapest_shop_id) {
                $starts[] = ['segment' => $segment, 'from' => $previous?->cheapest_price];
            }

            $previous = $segment;
        }

        $changes = [];

        foreach ($starts as $index => $start) {
            $next = $starts[$index + 1] ?? null;
            $changes[] = [...$start, 'until' => $next === null ? null : $next['segment']->started_at];
        }

        return $changes;
    }

    /**
     * First match wins. A rejected drop whose answering reading still alerted
     * (100, then 40, then 50: still a large drop) says nothing: the price moved
     * again, and the next row carries that alert.
     */
    private static function actionFor(?LargeDropCheck $check, bool $alerted, bool $reached): ?PriceChangeAction
    {
        return match (true) {
            $check?->outcome === LargeDropCheckOutcome::Rejected => $alerted ? null : PriceChangeAction::WrongPriceCaught,
            $check?->outcome === LargeDropCheckOutcome::Confirmed => $alerted ? PriceChangeAction::ConfirmedAlert : PriceChangeAction::Confirmed,
            $alerted => PriceChangeAction::Alert,
            $reached => PriceChangeAction::ReachedAlertPrice,
            $check instanceof LargeDropCheck => $check->asked_at->isAfter(now()->subHours(self::RECHECK_GIVES_UP_AFTER_HOURS))
                ? PriceChangeAction::Rechecking
                : PriceChangeAction::RecheckFailed,
            default => null,
        };
    }

    /**
     * @param  iterable<TargetPriceEvent>  $reached
     */
    private static function reachedIn(iterable $reached, ProductCheapestHistory $segment, ?CarbonImmutable $until): bool
    {
        foreach ($reached as $event) {
            if ($event->shop_id === $segment->cheapest_shop_id
                && ! $event->fired_at->isBefore($segment->started_at)
                && ($until === null || $event->fired_at->isBefore($until))) {
                return true;
            }
        }

        return false;
    }

    private static function changePct(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null || (float) $from <= 0.0) {
            return null;
        }

        return (int) round(((float) $to - (float) $from) / (float) $from * 100);
    }
}
