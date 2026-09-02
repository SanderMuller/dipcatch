<?php declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Models\PriceDropEvent;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class SavingsByMonthChartWidget extends ChartWidget
{
    protected ?string $heading = 'Savings by month';

    protected ?string $description = 'Total saved across all fired alerts, last 12 months. One dataset per currency, with no currency conversion.';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /**
     * An empty twelve-month axis says nothing; the chart appears once the
     * first drop has fired for this user.
     */
    public static function canView(): bool
    {
        return PriceDropEvent::query()->where('user_id', auth()->id())->exists();
    }

    /**
     * @return array{datasets: list<array{label: string, currency: string, data: list<float>}>, labels: list<string>}
     */
    protected function getData(): array
    {
        return $this->computeData();
    }

    /**
     * @return array{datasets: list<array{label: string, currency: string, data: list<float>}>, labels: list<string>}
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
                'label' => MoneyFormatter::symbol($currency) . ' saved',
                'currency' => strtoupper($currency),
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
