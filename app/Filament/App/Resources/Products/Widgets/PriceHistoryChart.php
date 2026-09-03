<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Widgets;

use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class PriceHistoryChart extends ChartWidget
{
    protected ?string $heading = 'Cheapest price history';

    protected int|string|array $columnSpan = 'full';

    // Enough to read the shape of the line without pushing the shops off
    // the page.
    protected ?string $maxHeight = '260px';

    public ?Product $record = null;

    public ?string $filter = '90';

    /**
     * @return array<int|string, bool|float|int|string>|null
     */
    protected function getFilters(): ?array
    {
        return [
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            '365' => 'Last 365 days',
            'all' => 'All time',
        ];
    }

    protected function getData(): array
    {
        return $this->computeData();
    }

    /**
     * @return array<string, mixed>
     */
    public function computeData(): array
    {
        $product = $this->record;
        if (! $product instanceof Product) {
            return ['datasets' => [], 'labels' => []];
        }

        $segments = $this->segmentsFor($product);
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

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * A PHP array cannot carry a JS function, so the tooltip callback ships as
     * RawJs. It formats with the browser's own ICU, using the `currency` field
     * every dataset carries — the same CLDR rules PHP intl applies server-side.
     */
    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const value = ctx.parsed.y;
                                if (value === null || value === undefined) {
                                    return ctx.dataset.label;
                                }

                                const money = new Intl.NumberFormat('en-US', {
                                    style: 'currency',
                                    currency: ctx.dataset.currency,
                                }).format(value);

                                return `${ctx.dataset.label}: ${money}`;
                            },
                        },
                    },
                },
            }
            JS);
    }

    /**
     * @return EloquentCollection<int, ProductCheapestHistory>
     */
    private function segmentsFor(Product $product): EloquentCollection
    {
        $query = $product->cheapestHistory()
            ->oldest('started_at');

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
        $filter = $this->filter ?? '90';
        if ($filter === 'all') {
            return null;
        }

        $days = max(1, (int) $filter);

        return CarbonImmutable::now()->subDays($days);
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
