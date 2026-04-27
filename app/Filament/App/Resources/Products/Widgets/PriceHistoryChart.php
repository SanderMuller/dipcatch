<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Widgets;

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class PriceHistoryChart extends ChartWidget
{
    protected ?string $heading = 'Price history';

    protected int|string|array $columnSpan = 'full';

    /** Product is set on the widget by the view page (passed via the view's data). */
    public ?Product $record = null;

    /** Default lookback window — bound to Filament's chart-widget filter dropdown. */
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

        $checks = $this->checksFor($product);

        /** @var list<string> $labels */
        $labels = [];
        /** @var list<float> $points */
        $points = [];
        /** @var list<int> $checkIds */
        $checkIds = [];
        foreach ($checks as $check) {
            $checkedAt = $check->checked_at;
            if (! $checkedAt instanceof CarbonInterface) {
                continue;
            }
            $labels[] = $checkedAt->toDateTimeString();
            $points[] = (float) $check->price;
            $checkIds[] = (int) $check->id;
        }

        $reference = $this->referencePrice($product);
        $thresholdLow = $this->thresholdLow($product, $reference);
        $markers = $this->notificationMarkers($product, $checkIds);

        $datasets = [];
        $datasets[] = [
            'label' => 'Price (' . $product->currency . ')',
            'data' => $points,
            'borderColor' => '#6366f1',
            'tension' => 0.2,
        ];
        $datasets[] = [
            'label' => 'Reference (30d median)',
            'data' => array_fill(0, count($points), $reference),
            'borderColor' => '#3b82f6',
            'borderDash' => [],
            'pointRadius' => 0,
        ];
        if ($thresholdLow !== null) {
            $datasets[] = [
                'label' => 'Threshold low',
                'data' => array_fill(0, count($points), $thresholdLow),
                'borderColor' => '#f59e0b',
                'borderDash' => [6, 4],
                'pointRadius' => 0,
            ];
        }
        $datasets[] = [
            'label' => 'Initial',
            'data' => array_fill(0, count($points), (float) $product->initial_price),
            'borderColor' => '#9ca3af',
            'borderDash' => [4, 4],
            'pointRadius' => 0,
        ];
        if ($markers !== []) {
            $datasets[] = [
                'label' => 'Notified',
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
     * @return EloquentCollection<int, PriceCheck>
     */
    private function checksFor(Product $product): EloquentCollection
    {
        $query = $product->priceChecks()
            ->where('status', ScrapeStatus::Ok)
            ->oldest('checked_at');

        $filter = $this->filter ?? '90';
        if ($filter !== 'all') {
            $days = max(1, (int) $filter);
            $query->where('checked_at', '>=', CarbonImmutable::now()->subDays($days));
        }

        return $query->get();
    }

    private function referencePrice(Product $product): float
    {
        /** @var Collection<int, float> $window */
        $window = $product->priceChecks()
            ->where('status', ScrapeStatus::Ok)
            ->where('checked_at', '>=', CarbonImmutable::now()->subDays(30))
            ->pluck('price')
            ->map(fn (mixed $p): float => (float) (is_scalar($p) ? $p : 0))
            ->sort()
            ->values();

        if ($window->count() < 7) {
            return (float) $product->initial_price;
        }

        return self::median(array_values($window->all()));
    }

    private function thresholdLow(Product $product, float $reference): ?float
    {
        $abs = $product->drop_threshold_abs === null ? null : (float) $product->drop_threshold_abs;
        $pct = $product->drop_threshold_pct === null ? null : (float) $product->drop_threshold_pct;

        $candidates = [];
        if ($abs !== null) {
            $candidates[] = $abs;
        }
        if ($pct !== null) {
            $candidates[] = $reference * $pct / 100.0;
        }
        if ($candidates === []) {
            return null;
        }

        return $reference - max($candidates);
    }

    /**
     * @param  list<int>  $checkIds
     * @return list<float|null>
     */
    private function notificationMarkers(Product $product, array $checkIds): array
    {
        if ($checkIds === []) {
            return [];
        }

        $events = PriceDropEvent::query()
            ->where('product_id', $product->id)
            ->whereIn('price_check_id', $checkIds)
            ->pluck('new_price', 'price_check_id');

        $out = [];
        foreach ($checkIds as $id) {
            $value = $events->get($id);
            $out[] = is_scalar($value) ? (float) $value : null;
        }

        return $out;
    }

    /**
     * @param  list<float>  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = (int) ($count / 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2.0;
    }
}
