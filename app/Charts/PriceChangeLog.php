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
 * Built from the same segments the "Lowest price" line draws. A segment that
 * moved only the best value, the pack size or a bundle is not a price change
 * and is skipped. Four queries whatever the row count.
 */
final readonly class PriceChangeLog
{
    /** An open re-check older than this lost its second reading. */
    private const int RECHECK_HOURS = 24;

    public function __construct(private Product $product, private ?CarbonImmutable $windowStart) {}

    /**
     * @return list<array{at: CarbonImmutable, shop: ?string, from: ?string, to: ?string, changePct: ?int, action: ?PriceChangeAction}>
     */
    public function rows(): array
    {
        $segments = $this->product->cheapestHistory()
            ->inOrder()
            ->overlapping($this->windowStart)
            ->with('cheapestShop')
            ->get();

        $triggers = $segments->pluck('triggering_price_check_id')->filter()->values()->all();
        $checks = LargeDropCheck::query()->whereIn('price_check_id', $triggers)->get()->keyBy('price_check_id');
        $alerted = PriceDropEvent::query()->whereIn('price_check_id', $triggers)->pluck('price_check_id')->flip();
        $reached = TargetPriceEvent::query()
            ->where('product_id', $this->product->id)
            ->when($this->windowStart, fn (Builder $query, CarbonImmutable $start): Builder => $query->where('fired_at', '>=', $start))
            ->get(['shop_id', 'fired_at']);

        $rows = [];
        $previous = null;

        foreach ($segments as $segment) {
            $isChange = $previous === null
                || $segment->cheapest_price !== $previous->cheapest_price
                || $segment->cheapest_shop_id !== $previous->cheapest_shop_id;

            if ($isChange && ($this->windowStart === null || ! $segment->started_at->isBefore($this->windowStart))) {
                $trigger = $segment->triggering_price_check_id;

                $rows[] = [
                    'at' => $segment->started_at,
                    'shop' => $segment->hostOf($segment->cheapestShop),
                    'from' => $previous?->cheapest_price,
                    'to' => $segment->cheapest_price,
                    'changePct' => self::changePct($previous?->cheapest_price, $segment->cheapest_price),
                    'action' => $this->actionFor($segment, $trigger === null ? null : $checks->get($trigger), $trigger !== null && $alerted->has($trigger), $reached),
                ];
            }

            $previous = $segment;
        }

        return array_reverse($rows);
    }

    /**
     * First match wins, so a caught wrong price is never also called an alert.
     *
     * @param  iterable<TargetPriceEvent>  $reached
     */
    private function actionFor(ProductCheapestHistory $segment, ?LargeDropCheck $check, bool $alerted, iterable $reached): ?PriceChangeAction
    {
        if ($check?->outcome === LargeDropCheckOutcome::Rejected) {
            return PriceChangeAction::WrongPriceCaught;
        }

        if ($check?->outcome === LargeDropCheckOutcome::Confirmed || $alerted) {
            return PriceChangeAction::Alert;
        }

        foreach ($reached as $event) {
            if ($event->shop_id === $segment->cheapest_shop_id
                && ! $event->fired_at->isBefore($segment->started_at)
                && ($segment->ended_at === null || $event->fired_at->isBefore($segment->ended_at))) {
                return PriceChangeAction::ReachedAlertPrice;
            }
        }

        if ($check instanceof LargeDropCheck) {
            return $check->asked_at->isAfter(now()->subHours(self::RECHECK_HOURS))
                ? PriceChangeAction::Rechecking
                : PriceChangeAction::RecheckFailed;
        }

        return null;
    }

    private static function changePct(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null || (float) $from <= 0.0) {
            return null;
        }

        return (int) round(((float) $to - (float) $from) / (float) $from * 100);
    }
}
