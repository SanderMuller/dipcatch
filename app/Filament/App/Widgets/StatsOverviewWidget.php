<?php declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
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
        // Savings now come from the firehose of recorded drops — sum of
        // `drop_abs` per currency over price_drop_events. This replaces the
        // pre-refactor "initial_price - last_price" snapshot, which the
        // multi-webshop schema no longer carries on Product.
        $totals = $this->savingsByCurrency($userId);
        $defaultCurrency = $this->userDefaultCurrency();

        if ($totals === []) {
            return Stat::make('Lifetime savings', MoneyFormatter::format('0', $defaultCurrency))
                ->description('No drops fired yet. Keep tracking.')
                ->icon('heroicon-o-banknotes')
                ->color('gray');
        }

        $primary = $totals[$defaultCurrency] ?? null;
        if ($primary === null) {
            arsort($totals);
            $defaultCurrency = array_key_first($totals);
            $primary = $totals[$defaultCurrency];
        }

        $primaryFormatted = MoneyFormatter::format((string) $primary, $defaultCurrency);

        $others = $totals;
        unset($others[$defaultCurrency]);

        $description = $others === []
            ? 'Total saved across all fired drops.'
            : 'Also: ' . collect($others)
                ->map(fn (float $amount, string $code): string => MoneyFormatter::format((string) $amount, $code))
                ->values()
                ->implode(' · ') . '  (FX not converted in v1)';

        return Stat::make('Lifetime savings', $primaryFormatted)
            ->description($description)
            ->icon('heroicon-o-banknotes')
            ->color('success');
    }

    /**
     * @return array<string, float>
     */
    private function savingsByCurrency(int $userId): array
    {
        $rows = PriceDropEvent::query()
            ->where('user_id', $userId)
            ->selectRaw('currency, SUM(drop_abs) as savings')
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
