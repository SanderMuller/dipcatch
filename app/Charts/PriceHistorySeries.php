<?php declare(strict_types=1);

namespace App\Charts;

use App\Billing\Entitlements;
use App\Billing\HistoryWindow;
use App\Billing\Plan;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Support\MoneyFormatter;
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
     * @return array{rows: list<array<string, mixed>>, currency: string, unitLabel: ?string, hasNotified: bool}
     */
    public function fluxChart(): array
    {
        return PriceHistoryFluxChart::fromData($this->data(), strtoupper($this->product->currency));
    }

    /**
     * @return array{datasets: list<array<string, mixed>>, labels: list<string>}
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
        foreach ($segments as $segment) {
            $started = $segment->started_at;
            if (! $started instanceof CarbonInterface) {
                continue;
            }
            $labels[] = self::formatStamp($started);
            $points[] = $segment->cheapest_price === null
                ? null
                : (float) $segment->cheapest_price;
        }

        $current = $segments->last();
        if ($current instanceof ProductCheapestHistory) {
            $labels[] = self::formatStamp($now);
            $points[] = $current->cheapest_price === null
                ? null
                : (float) $current->cheapest_price;
        }

        $unit = $this->unitSeries($segments, $current instanceof ProductCheapestHistory);

        $markers = $this->notificationMarkers($product, $segments, $labels);

        $datasets = [
            [
                'label' => 'Cheapest (' . MoneyFormatter::symbol($product->currency) . ')',
                'currency' => strtoupper($product->currency),
                'data' => $points,
                'borderColor' => '#6366f1',
                'stepped' => true,
                'tension' => 0,
            ],
        ];

        // A second line, on its own axis: the same cheapest price stated per
        // unit. The two only diverge where the cheapest shop changes — which
        // is exactly where a cheaper total can be worse value.
        if ($unit !== null) {
            $datasets[] = [
                'label' => 'Cheapest per ' . ltrim($unit['label'], '/') . ' (' . MoneyFormatter::symbol($product->currency) . ')',
                'currency' => strtoupper($product->currency),
                'data' => $unit['points'],
                'borderColor' => '#0ea5e9',
                'borderDash' => [6, 4],
                'stepped' => true,
                'tension' => 0,
                'yAxisID' => 'unit',
            ];
        }

        if ($markers !== []) {
            $datasets[] = [
                'label' => 'Notified',
                'currency' => strtoupper($product->currency),
                'data' => $markers,
                'borderColor' => '#dc2626',
                'backgroundColor' => '#dc2626',
                'pointRadius' => 6,
                'showLine' => false,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    /**
     * The cheapest price stated per unit, point for point with the price
     * line, or null when no shop in view states a pack size.
     *
     * Pack sizes are read as they are today — the app keeps no history of
     * them — so a shop that changed its pack would restate its own past.
     * Shops rarely do, and the alternative is no line at all.
     *
     * Only one unit can share an axis: EUR/kg and EUR/piece are not the same
     * measure, so the unit most segments use wins and the rest read as gaps.
     *
     * @param  EloquentCollection<int, ProductCheapestHistory>  $segments
     * @return array{label: string, points: list<float|null>}|null
     */
    private function unitSeries(EloquentCollection $segments, bool $repeatCurrent): ?array
    {
        $units = [];

        foreach ($segments as $segment) {
            $label = $segment->cheapestShop?->packUnitLabel();

            if ($label !== null) {
                $units[$label] = ($units[$label] ?? 0) + 1;
            }
        }

        if ($units === []) {
            return null;
        }

        arsort($units);
        $label = (string) array_key_first($units);

        $points = [];

        foreach ($segments as $segment) {
            $points[] = self::unitPointFor($segment, $label);
        }

        if ($repeatCurrent) {
            $last = $segments->last();
            $points[] = $last instanceof ProductCheapestHistory ? self::unitPointFor($last, $label) : null;
        }

        return array_filter($points, static fn (?float $point): bool => $point !== null) === []
            ? null
            : ['label' => $label, 'points' => $points];
    }

    private static function unitPointFor(ProductCheapestHistory $segment, string $label): ?float
    {
        $shop = $segment->cheapestShop;

        if ($shop === null || $shop->packUnitLabel() !== $label) {
            return null;
        }

        $unitPrice = $shop->unitPriceFor($segment->cheapest_price);

        return $unitPrice === null ? null : (float) $unitPrice;
    }

    /**
     * @return EloquentCollection<int, ProductCheapestHistory>
     */
    private function segmentsFor(Product $product): EloquentCollection
    {
        $query = $product->cheapestHistory()
            ->inOrder();

        $windowStart = $this->windowStart();
        if ($windowStart !== null) {
            // Include any segment that overlaps the window — `started_at < window`
            // but still active (`ended_at IS NULL` or `ended_at >= window`).
            // Otherwise long-lived current prices disappear from the left edge.
            $query->where(function (EloquentBuilder $q) use ($windowStart): void {
                $q->where('started_at', '>=', $windowStart)
                    ->orWhereNull('ended_at')
                    ->orWhere('ended_at', '>=', $windowStart);
            });
        }

        return $query->get();
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
     * skipped. Returns one float|null per label so it aligns with the cheapest
     * dataset.
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
