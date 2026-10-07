<?php declare(strict_types=1);

namespace App\Charts;

use App\Enums\LargeDropCheckOutcome;
use App\Enums\ScrapeStatus;
use App\Models\LargeDropCheck;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * The changes of a product's lowest price, newest first, each with what
 * DipCatch did about it: the list under the price chart.
 *
 * Built from the history segments, on the basis the product's alerts use: the
 * best value per kilo, litre or piece once the product compares in a unit,
 * the lowest pack price otherwise. A large drop is judged on that same basis,
 * so its re-check lands on a row here. A segment that moves nothing on the
 * basis is not a change: it is skipped, and the change before it runs on
 * through it.
 */
final readonly class PriceChangeLog
{
    private const int RECHECK_GIVES_UP_AFTER_HOURS = 24;

    public function __construct(private Product $product, private ?CarbonImmutable $windowStart) {}

    /**
     * `unit` is the comparison unit the prices are per (`g`, `ml`, `piece`), or
     * null when they are pack prices.
     *
     * `actionShop` names the shop an action is about when that is not the row's
     * own shop: the one that went out of stock.
     *
     * @return list<array{at: CarbonImmutable, shop: ?string, from: ?string, to: ?string, unit: ?string, changePct: ?int, action: ?PriceChangeAction, actionShop: ?string}>
     */
    public function rows(): array
    {
        $changes = $this->changes();

        $triggers = array_values(array_filter(array_map(static fn (array $change): ?int => $change['segment']->triggering_price_check_id, $changes)));
        $readings = PriceCheck::query()->whereIn('id', $triggers)->get(['id', 'shop_id', 'status', 'in_stock'])->keyBy('id');
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

            $action = self::actionFor($check, $wasAlerted, self::reachedIn($reached, $change['shopId'], $segment->started_at, $change['until']))
                ?? self::droppedOut($trigger === null ? null : $readings->get($trigger), $change['previousShopId'], $change['shopId']);

            $rows[] = [
                'at' => $segment->started_at,
                'shop' => $segment->hostOf($change['shop']),
                'from' => $change['from'],
                'to' => $change['to'],
                'unit' => $this->unit(),
                'changePct' => self::changePct($change['from'], $change['to']),
                'action' => $action,
                'actionShop' => in_array($action, [PriceChangeAction::WentOutOfStock, PriceChangeAction::CouldNotRead], strict: true) ? $change['previousHost'] : null,
            ];
        }

        return array_reverse($rows);
    }

    /**
     * Each change of the price on the basis or of its shop, in order, with the
     * price before it and the moment the next change took over.
     *
     * @return list<array{segment: ProductCheapestHistory, shop: ?Shop, shopId: ?string, from: ?string, to: ?string, until: ?CarbonImmutable, previousShopId: ?string, previousHost: ?string}>
     */
    private function changes(): array
    {
        $segments = $this->product->cheapestHistory()
            ->inOrder()
            ->overlapping($this->windowStart)
            ->with('cheapestShop', 'bestValueShop')
            ->get();

        $starts = [];
        $previous = null;

        foreach ($segments as $segment) {
            $point = $this->pointOf($segment);

            // A segment in another unit, or one with no size, says nothing on
            // a per-unit basis: a kilo price beside a piece price is no change.
            if ($point === null) {
                continue;
            }

            if ($previous === null || $point['price'] !== $previous['price'] || $point['shopId'] !== $previous['shopId']) {
                $starts[] = [
                    'segment' => $segment,
                    'shop' => $point['shop'],
                    'shopId' => $point['shopId'],
                    'from' => $previous['price'] ?? null,
                    'to' => $point['price'],
                    'previousShopId' => $previous['shopId'] ?? null,
                    'previousHost' => $previous['host'] ?? null,
                ];
            }

            $previous = [...$point, 'host' => $segment->hostOf($point['shop'])];
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
     * Why the shop that held the price left it, when its own reading moved
     * the price: out of stock, or a page that could not be read. Another
     * shop's lower price is a change that explains itself.
     */
    private static function droppedOut(?PriceCheck $reading, ?string $previousShopId, ?string $shopId): ?PriceChangeAction
    {
        if ($reading === null || $previousShopId === null || $reading->shop_id !== $previousShopId || $shopId === $previousShopId) {
            return null;
        }

        return match (true) {
            $reading->status !== ScrapeStatus::Ok => PriceChangeAction::CouldNotRead,
            $reading->in_stock === false => PriceChangeAction::WentOutOfStock,
            default => null,
        };
    }

    /**
     * The comparison unit the alerts measure in, or null for pack prices. The
     * product's own column: the history may hold an older unit, which the
     * alerts no longer read.
     */
    private function unit(): ?string
    {
        return is_string($this->product->best_value_pack_unit) ? $this->product->best_value_pack_unit : null;
    }

    /**
     * The price and shop a segment puts on the basis: the best value per unit,
     * or the cheapest pack. Null when the segment has no price on a per-unit
     * basis.
     *
     * @return array{price: ?string, shop: ?Shop, shopId: ?string}|null
     */
    private function pointOf(ProductCheapestHistory $segment): ?array
    {
        $unit = $this->unit();

        if ($unit === null) {
            return ['price' => $segment->cheapest_price, 'shop' => $segment->cheapestShop, 'shopId' => $segment->cheapest_shop_id];
        }

        $unitPrice = $segment->packSize()?->unit === $unit ? $segment->unitPrice() : null;

        if ($unitPrice === null) {
            return null;
        }

        return $segment->best_value_price === null
            ? ['price' => $unitPrice, 'shop' => $segment->cheapestShop, 'shopId' => $segment->cheapest_shop_id]
            : ['price' => $unitPrice, 'shop' => $segment->bestValueShop, 'shopId' => $segment->best_value_shop_id];
    }

    /**
     * @param  iterable<TargetPriceEvent>  $reached
     */
    private static function reachedIn(iterable $reached, ?string $shopId, CarbonImmutable $from, ?CarbonImmutable $until): bool
    {
        foreach ($reached as $event) {
            if ($event->shop_id === $shopId
                && ! $event->fired_at->isBefore($from)
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
