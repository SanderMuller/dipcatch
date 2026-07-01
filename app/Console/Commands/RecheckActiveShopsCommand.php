<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ShopHealth;
use App\Jobs\CheckShopPrice;
use App\Models\Shop;
use App\Support\Config as DipConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;

#[Signature('dipcatch:recheck-offers')]
#[Description('Dispatch CheckShopPrice jobs for offers that are due for a recheck.')]
class RecheckActiveShopsCommand extends Command
{
    public function handle(): int
    {
        $intervalHours = DipConfig::int('dipcatch.recheck.interval_hours', 6);
        $jitterMinutes = DipConfig::int('dipcatch.recheck.jitter_minutes', 30);
        $batchSize = DipConfig::int('dipcatch.scheduler.batch_size', 200);

        $cutoff = now()->subHours($intervalHours);
        $dispatched = 0;

        Shop::query()
            ->where('active', true)
            ->where('health', '!=', ShopHealth::Dead->value)
            ->whereHas('product', function (EloquentQueryBuilder $q): void {
                $q->where('active', true);
            })
            ->where(function (EloquentQueryBuilder $q) use ($cutoff): void {
                $q->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', $cutoff);
            })
            ->orderByRaw('last_checked_at IS NULL DESC')
            ->oldest('last_checked_at')
            ->limit($batchSize)
            ->each(function (Shop $shop) use ($jitterMinutes, &$dispatched): void {
                $delay = random_int(0, max(0, $jitterMinutes * 60));
                dispatch(new CheckShopPrice($shop))
                    ->onQueue('scrapes')
                    ->delay(now()->addSeconds($delay));
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} CheckShopPrice jobs.");

        return self::SUCCESS;
    }
}
