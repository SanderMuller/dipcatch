<?php declare(strict_types=1);

namespace App\Charts;

use App\Billing\Entitlements;
use App\Billing\HistoryWindow;
use App\Billing\Plan;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Support\BundlePriceLabel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The cheapest-price series a chart plots, and the plan window it is clamped to.
 *
 * Extracted from the Filament widget so it survives that panel's deletion (see
 * specs/flux-user-app-migration.md). The clamp is the reason this is not merely
 * a view concern: `$filter` reaches it from the client, so the menu an account
 * is shown is never the enforcement point — `HistoryWindow::start()` is.
 */
final readonly class PriceHistorySeries
{
    public function __construct(
        private Product $product,
        private ?string $filter = null,
    ) {}

    /**
     * Null when the account may read everything.
     *
     * An unknown owner falls back to the free ceiling rather than to
     * unlimited: a gate that opens when it cannot identify who is asking is
     * not a gate.
     */
    private function historyDays(): ?int
    {
        $user = $this->product->user;

        return $user === null
            ? Entitlements::of(Plan::Free)->historyDays()
            : $user->entitlements()->historyDays();
    }

    /**
     * Rows a Flux line chart can plot. Null prices stay as gaps; notified
     * points are omitted when no alert fired on that stamp.
     *
     * @return array{rows: list<array<string, mixed>>, currency: string, unit: ?string, unitDecimals: int, hasNotified: bool, hasBundles: bool, unitCoverage: float}
     */
    public function fluxChart(): array
    {
        return PriceHistoryFluxChart::fromData($this->data(), strtoupper($this->product->currency));
    }

    /**
     * One entry per stamp in every list. `unit` is null when no segment in view
     * states a pack size; `notified` holds the alerted price at the stamp an
     * alert fired on, null elsewhere.
     *
     * @return array{labels: list<string>, price: list<float|null>, unit: array{unit: string, points: list<float|null>}|null, notified: list<float|null>, bundleConditions: list<?string>}
     */
    public function data(): array
    {
        $product = $this->product;

        $segments = $this->segmentsFor($product)->load('cheapestShop');
        $now = CarbonImmutable::now();

        /** @var list<string> $labels */
        $labels = [];
        /** @var list<float|null> $points */
        $points = [];
        /** @var list<?string> $bundleConditions */
        $bundleConditions = [];
        foreach ($segments as $segment) {
            $started = $segment->started_at;
            if (! $started instanceof CarbonInterface) {
                continue;
            }
            $labels[] = self::formatStamp($started);
            $points[] = $segment->cheapest_price === null
                ? null
                : (float) $segment->cheapest_price;
            $bundleConditions[] = BundlePriceLabel::forHistory($segment, $product->currency);
        }

        $current = $segments->last();
        if ($current instanceof ProductCheapestHistory) {
            $labels[] = self::formatStamp($now);
            $points[] = $current->cheapest_price === null
                ? null
                : (float) $current->cheapest_price;
            $bundleConditions[] = BundlePriceLabel::forHistory($current, $product->currency);
        }

        $unit = $this->unitSeries($segments, $current instanceof ProductCheapestHistory);

        $markers = $this->notificationMarkers($product, $segments, $labels);

        return [
            'labels' => $labels,
            'price' => $points,
            'unit' => $unit,
            'notified' => $markers,
            'bundleConditions' => $bundleConditions,
        ];
    }

    /**
     * The best value stated per unit, point for point with the price line, or
     * null when no shop in view states a pack size.
     *
     * Only one unit can share an axis: EUR/kg and EUR/piece are not the same
     * measure, so the unit most segments use wins and the rest read as gaps.
     *
     * @param  EloquentCollection<int, ProductCheapestHistory>  $segments
     * @return array{unit: string, points: list<float|null>}|null
     */
    private function unitSeries(EloquentCollection $segments, bool $repeatCurrent): ?array
    {
        $units = [];

        foreach ($segments as $segment) {
            $unit = $segment->packSize()?->unit;

            if ($unit !== null) {
                $units[$unit] = ($units[$unit] ?? 0) + 1;
            }
        }

        if ($units === []) {
            return null;
        }

        arsort($units);
        $unit = (string) array_key_first($units);

        $points = [];

        foreach ($segments as $segment) {
            $points[] = self::unitPointFor($segment, $unit);
        }

        if ($repeatCurrent) {
            $last = $segments->last();
            $points[] = $last instanceof ProductCheapestHistory ? self::unitPointFor($last, $unit) : null;
        }

        return array_filter($points, static fn (?float $point): bool => $point !== null) === []
            ? null
            : ['unit' => $unit, 'points' => $points];
    }

    /**
     * Each point is drawn from the size that segment recorded, never from the
     * shop's size today.
     *
     * Reading today's size rewrites the past: Foodello reported 55 g for a box
     * of twelve, and correcting it to 660 g would have redrawn a month of points
     * twelvefold. A segment with no recorded size plots nothing rather than a
     * plausible number.
     */
    private static function unitPointFor(ProductCheapestHistory $segment, string $unit): ?float
    {
        if ($segment->packSize()?->unit !== $unit) {
            return null;
        }

        $unitPrice = $segment->unitPrice();

        return $unitPrice === null ? null : (float) $unitPrice;
    }

    /**
     * @return EloquentCollection<int, ProductCheapestHistory>
     */
    private function segmentsFor(Product $product): EloquentCollection
    {
        return $product->cheapestHistory()
            ->inOrder()
            ->overlapping($this->windowStart())
            ->get();
    }

    /**
     * The cutoff matching the active filter, or null when "All time" is
     * selected. Shared by segment + notification-marker queries so the
     * chart and its markers always agree on what's "in view".
     */
    private function windowStart(): ?CarbonImmutable
    {
        return HistoryWindow::start($this->historyDays(), $this->filter);
    }

    private static function formatStamp(CarbonInterface $dt): string
    {
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Map each price_drop_event onto the chart segment whose [started_at, ended_at)
     * interval contains the event's `fired_at`. Events outside any segment are
     * skipped. Returns one float|null per label so it aligns with the price list.
     *
     * @param  EloquentCollection<int, ProductCheapestHistory>  $segments
     * @param  list<string>  $labels
     * @return list<float|null>
     */
    private function notificationMarkers(Product $product, EloquentCollection $segments, array $labels): array
    {
        if ($labels === []) {
            return [];
        }

        // Scope markers to the same window as the line — otherwise old drop
        // events on a still-active long segment leak into "Last 30 days".
        $events = PriceDropEvent::query()
            ->where('product_id', $product->id)
            ->when(
                $this->windowStart(),
                fn (EloquentBuilder $q, CarbonImmutable $windowStart) => $q->where('fired_at', '>=', $windowStart),
            )
            ->oldest('fired_at')
            ->get(['new_price', 'fired_at']);

        if ($events->isEmpty()) {
            return array_fill(0, count($labels), null);
        }

        // Build segment index → marker price. Last label corresponds to "now"
        // (the active open segment).
        $markers = array_fill(0, count($labels), null);

        foreach ($events as $event) {
            $firedAt = $event->fired_at;
            if (! $firedAt instanceof CarbonInterface) {
                continue;
            }
            $index = $this->findSegmentIndex($segments, $firedAt);
            if ($index === null) {
                continue;
            }
            $markers[$index] = (float) $event->new_price;
        }

        return array_values($markers);
    }

    /**
     * @param  EloquentCollection<int, ProductCheapestHistory>  $segments
     */
    private function findSegmentIndex(EloquentCollection $segments, CarbonInterface $firedAt): ?int
    {
        $i = 0;
        foreach ($segments as $segment) {
            $started = $segment->started_at;
            if (! $started instanceof CarbonInterface) {
                $i++;

                continue;
            }
            $ended = $segment->ended_at;
            $endTime = $ended instanceof CarbonInterface ? $ended : null;

            $inSegment = ! $firedAt->isBefore($started)
                && ($endTime === null || $firedAt->isBefore($endTime));

            if ($inSegment) {
                return $i;
            }

            $i++;
        }

        return null;
    }
}
