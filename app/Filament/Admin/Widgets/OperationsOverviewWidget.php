<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\ShopHealth;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * How the app is doing, independent of whether anything is on sale.
 *
 * The revenue widget beside this one hides itself until Stripe is
 * configured, which left the dashboard empty on a working installation.
 * These numbers come from tables that always exist.
 */
class OperationsOverviewWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 1;

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        return [
            $this->accountsStat(),
            $this->trackingStat(),
            $this->scrapeHealthStat(),
            $this->alertsStat(),
        ];
    }

    private function accountsStat(): Stat
    {
        $total = User::query()->count();
        $active = User::query()
            ->whereHas('products', fn (EloquentBuilder $query): EloquentBuilder => $query->where('active', true))
            ->count();

        return Stat::make('Accounts', $total)
            ->description($active . ' tracking something')
            ->icon('heroicon-o-users')
            ->color('primary');
    }

    private function trackingStat(): Stat
    {
        $products = Product::query()->where('active', true)->count();
        $offers = $this->watchedShops()->count();

        return Stat::make('Active products', $products)
            ->description($offers . ' offers watched')
            ->icon('heroicon-o-shopping-bag')
            ->color('primary');
    }

    /**
     * The one number that says whether the product still works: an offer the
     * scraper cannot read is a price nobody is watching.
     */
    private function scrapeHealthStat(): Stat
    {
        $ok = $this->activeShopsWith(ShopHealth::Ok);
        $failing = $this->activeShopsWith(ShopHealth::Failing);
        $dead = $this->activeShopsWith(ShopHealth::Dead);
        $total = $ok + $failing + $dead;

        $percent = $total === 0 ? 100 : (int) round($ok / $total * 100);

        return Stat::make('Scrapes healthy', $percent . '%')
            ->description($failing . ' failing, ' . $dead . ' dead')
            ->icon('heroicon-o-heart')
            ->color(match (true) {
                $dead > 0 || $percent < 80 => 'danger',
                $failing > 0 => 'warning',
                default => 'success',
            });
    }

    private function activeShopsWith(ShopHealth $health): int
    {
        return $this->watchedShops()
            ->where('health', $health->value)
            ->count();
    }

    /**
     * Offers the scheduler would actually check: an active offer whose
     * product is also active. Pausing a product stops its offers being
     * rechecked, so counting them here would report work nobody is doing —
     * and let a paused, dead offer drag the health figure down.
     *
     * @return EloquentBuilder<Shop>
     */
    private function watchedShops(): EloquentBuilder
    {
        return Shop::query()
            ->where('active', true)
            ->whereHas('product', fn (EloquentBuilder $query): EloquentBuilder => $query->where('active', true));
    }

    private function alertsStat(): Stat
    {
        $since = CarbonImmutable::now()->subDay();
        $today = PriceDropEvent::query()->where('fired_at', '>=', $since)->count();
        $week = PriceDropEvent::query()
            ->where('fired_at', '>=', CarbonImmutable::now()->subWeek())
            ->count();

        return Stat::make('Drops alerted (24h)', $today)
            ->description($week . ' in the last 7 days')
            ->icon('heroicon-o-bell-alert')
            ->color($today > 0 ? 'success' : 'gray');
    }
}
