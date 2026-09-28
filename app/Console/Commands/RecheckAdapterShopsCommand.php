<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckShopPrice;
use App\Models\Shop;
use App\Support\RecheckJitter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dipcatch:recheck-adapter {adapter : The adapter key, e.g. zooplus} {--limit=500} {--dry-run}')]
#[Description('Recheck every tracked shop one adapter reads, so a fix to that adapter reaches stored rows without waiting for the schedule.')]
final class RecheckAdapterShopsCommand extends Command
{
    public function handle(): int
    {
        $adapter = (string) $this->argument('adapter');

        $shops = Shop::query()
            ->tracked()
            ->where('active', true)
            ->where('adapter_key', $adapter)
            ->limit((int) $this->option('limit'))
            ->get();

        if ($this->option('dry-run')) {
            $this->info("{$shops->count()} {$adapter} shop(s) would be rechecked.");

            return self::SUCCESS;
        }

        // Spread like the scheduled run: every recheck costs the shop a request.
        $jitterSeconds = RecheckJitter::maxSeconds();

        $shops->each(function (Shop $shop) use ($jitterSeconds): void {
            dispatch(new CheckShopPrice($shop))->delay(now()->addSeconds(random_int(0, $jitterSeconds)));
        });

        $this->info("Dispatched {$shops->count()} {$adapter} recheck(s).");

        return self::SUCCESS;
    }
}
