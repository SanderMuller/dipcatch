<?php declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Models\Product;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    /**
     * Render synchronously (small COUNT/SUM queries, above the fold). Heavier
     * widgets — `ActiveDropsTableWidget`, `RecentNotificationsTableWidget`,
     * `SavingsByMonthChartWidget`, `PriceHistoryChart` — keep Filament's
     * default lazy hydration.
     */
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $userId = (int) auth()->id();

        return [
            Stat::make('Tracked products', $this->trackedProducts($userId))
                ->description('Active products on your watch list.')
                ->icon('heroicon-o-shopping-bag')
                ->color('primary'),

            Stat::make('Active drops', $this->activeDrops($userId))
                ->description('Below your threshold right now.')
                ->icon('heroicon-o-arrow-trending-down')
                ->color('success'),

            $this->lifetimeSavingsStat($userId),
        ];
    }

    private function trackedProducts(int $userId): int
    {
        return Product::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->count();
    }

    private function activeDrops(int $userId): int
    {
        return Product::query()
            ->where('user_id', $userId)
            ->whereNotNull('last_notified_price')
            ->count();
    }

    private function lifetimeSavingsStat(int $userId): Stat
    {
        $totals = $this->savingsByCurrency($userId);
        $defaultCurrency = $this->userDefaultCurrency();

        if ($totals === []) {
            return Stat::make('Lifetime savings', $defaultCurrency . ' 0.00')
                ->description('No saved cents yet — keep tracking.')
                ->icon('heroicon-o-banknotes')
                ->color('gray');
        }

        $primary = $totals[$defaultCurrency] ?? null;
        if ($primary === null) {
            // No products in user's default currency — pick the largest other.
            arsort($totals);
            $defaultCurrency = array_key_first($totals);
            $primary = $totals[$defaultCurrency];
        }

        $primaryFormatted = $defaultCurrency . ' ' . number_format($primary, 2, '.', ',');

        $others = $totals;
        unset($others[$defaultCurrency]);

        $description = $others === []
            ? 'Total dropped vs. each product\'s initial price.'
            : 'Also: ' . collect($others)
                ->map(fn (float $amount, string $code): string => $code . ' ' . number_format($amount, 2, '.', ','))
                ->values()
                ->implode(' · ') . '  (FX not converted in v1)';

        return Stat::make('Lifetime savings', $primaryFormatted)
            ->description($description)
            ->icon('heroicon-o-banknotes')
            ->color('success');
    }

    /**
     * @return array<string, float> currency code => sum of (initial_price - last_price)
     */
    private function savingsByCurrency(int $userId): array
    {
        $rows = Product::query()
            ->where('user_id', $userId)
            ->whereNotNull('last_price')
            ->whereColumn('last_price', '<', 'initial_price')
            ->selectRaw('currency, SUM(initial_price - last_price) as savings')
            ->groupBy('currency')
            ->pluck('savings', 'currency')
            ->all();

        $out = [];
        foreach ($rows as $code => $sum) {
            $out[(string) $code] = is_scalar($sum) ? (float) $sum : 0.0;
        }

        return $out;
    }

    private function userDefaultCurrency(): string
    {
        /** @var User|null $user */
        $user = auth()->user();

        return is_string($user?->default_currency) ? $user->default_currency : 'EUR';
    }
}
