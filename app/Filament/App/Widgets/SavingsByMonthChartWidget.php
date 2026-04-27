<?php declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Models\PriceDropEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\ChartWidget;

class SavingsByMonthChartWidget extends ChartWidget
{
    protected ?string $heading = 'Savings by month';

    protected ?string $description = 'Σ drop_abs from each fired alert, last 12 months. Per-currency datasets — no FX conversion in v1.';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{datasets: list<array{label: string, data: list<float>}>, labels: list<string>}
     */
    protected function getData(): array
    {
        return $this->computeData();
    }

    /**
     * @return array{datasets: list<array{label: string, data: list<float>}>, labels: list<string>}
     */
    public function computeData(): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(11);

        $monthLabels = [];
        $monthKeys = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->addMonths($i);
            $monthLabels[] = $month->format('M Y');
            $monthKeys[] = $month->format('Y-m');
        }

        $byCurrency = $this->aggregate($start);

        $datasets = [];
        foreach ($byCurrency as $currency => $monthly) {
            $row = [];
            foreach ($monthKeys as $key) {
                $row[] = $monthly[$key] ?? 0.0;
            }
            $datasets[] = [
                'label' => $currency,
                'data' => $row,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $monthLabels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, array<string, float>> currency => yyyy-mm => sum drop_abs
     */
    private function aggregate(CarbonImmutable $start): array
    {
        $rows = PriceDropEvent::query()
            ->where('user_id', auth()->id())
            ->where('fired_at', '>=', $start)
            ->get(['currency', 'drop_abs', 'fired_at']);

        $out = [];
        foreach ($rows as $event) {
            $firedAt = $event->fired_at;
            if (! $firedAt instanceof CarbonInterface) {
                continue;
            }
            $rawKey = $firedAt->format('Y-m');
            $key = is_scalar($rawKey) ? (string) $rawKey : '';
            $rawCurrency = $event->currency;
            $currency = is_scalar($rawCurrency) ? (string) $rawCurrency : '';
            if ($currency === '') {
                continue;
            }
            $out[$currency] ??= [];
            $out[$currency][$key] = ($out[$currency][$key] ?? 0.0) + (float) $event->drop_abs;
        }

        return $out;
    }
}
