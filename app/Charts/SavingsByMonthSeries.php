<?php declare(strict_types=1);

namespace App\Charts;

use App\Models\PriceDropEvent;
use App\Models\User;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the alerts saved, by month, over the last year.
 *
 * One dataset per currency and no conversion: adding cents of one currency to
 * cents of another produces a number that means nothing, and the admin revenue
 * figures already refuse to do it.
 */
final readonly class SavingsByMonthSeries
{
    private const int MONTHS = 12;

    /** @var list<string> */
    private const array SERIES_COLORS = [
        'text-blue-500 dark:text-blue-400',
        'text-emerald-500 dark:text-emerald-400',
        'text-amber-500 dark:text-amber-400',
        'text-violet-500 dark:text-violet-400',
        'text-rose-500 dark:text-rose-400',
        'text-cyan-500 dark:text-cyan-400',
    ];

    /** @var list<string> */
    private const array LEGEND_COLORS = [
        'bg-blue-500',
        'bg-emerald-500',
        'bg-amber-500',
        'bg-violet-500',
        'bg-rose-500',
        'bg-cyan-500',
    ];

    public function __construct(private User $user) {}

    /**
     * True once a drop has fired. An empty twelve-month axis says nothing, so
     * the dashboard leaves the chart out until there is something to plot.
     */
    public function hasData(): bool
    {
        return PriceDropEvent::query()->where('user_id', $this->user->id)->exists();
    }

    /**
     * Rows a Flux bar chart can plot. One field per currency, never converted.
     *
     * @return array{rows: list<array<string, float|string>>, series: list<array{field: string, label: string, color: string, legend: string, currency: string}>}
     */
    public function fluxChart(): array
    {
        $data = $this->data();
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(self::MONTHS - 1);
        $series = [];

        foreach ($data['datasets'] as $index => $dataset) {
            $currency = $dataset['currency'];

            $series[] = [
                'field' => $currency,
                'label' => $dataset['label'],
                'color' => self::SERIES_COLORS[$index % count(self::SERIES_COLORS)],
                'legend' => self::LEGEND_COLORS[$index % count(self::LEGEND_COLORS)],
                'currency' => $currency,
            ];
        }

        $rows = [];

        foreach (array_keys($data['labels']) as $index) {

            $row = ['date' => $start->addMonths($index)->toDateString()];

            foreach ($data['datasets'] as $dataset) {
                $row[$dataset['currency']] = $dataset['data'][$index] ?? 0.0;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'series' => $series];
    }

    /**
     * @return array{datasets: list<array{label: string, currency: string, data: list<float>}>, labels: list<string>}
     */
    public function data(): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(self::MONTHS - 1);

        $labels = [];
        $keys = [];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $start->addMonths($i);
            $labels[] = $month->format('M Y');
            $keys[] = $month->format('Y-m');
        }

        $datasets = [];

        foreach ($this->aggregate($start) as $currency => $monthly) {
            $row = [];

            foreach ($keys as $key) {
                $row[] = $monthly[$key] ?? 0.0;
            }

            $datasets[] = [
                'label' => MoneyFormatter::symbol($currency) . ' saved',
                'currency' => mb_strtoupper($currency),
                'data' => $row,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    /** A declared string return keeps the key type of the map below. */
    private static function monthKey(CarbonInterface $dt): string
    {
        return $dt->format('Y-m');
    }

    /**
     * @return array<string, array<string, float>> currency => yyyy-mm => summed drop
     */
    private function aggregate(CarbonImmutable $start): array
    {
        $rows = PriceDropEvent::query()
            ->where('user_id', $this->user->id)
            ->where('fired_at', '>=', $start)
            ->get(['currency', 'drop_abs', 'fired_at']);

        $out = [];

        foreach ($rows as $event) {
            $firedAt = $event->fired_at;

            if (! $firedAt instanceof CarbonInterface) {
                continue;
            }

            $currency = is_string($event->currency) && $event->currency !== '' ? $event->currency : 'EUR';
            $month = self::monthKey($firedAt);

            $current = $out[$currency][$month] ?? 0.0;
            $out[$currency][$month] = $current + (float) $event->drop_abs;
        }

        ksort($out);

        return $out;
    }
}
