<?php declare(strict_types=1);

namespace App\Health;

use App\Enums\ShopHealth;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class LastSuccessfulScrapeCheck extends Check
{
    private int $warnAfterHours = 48;

    private int $failAfterHours = 96;

    public function warnAfterHours(int $hours): self
    {
        $this->warnAfterHours = $hours;

        return $this;
    }

    public function failAfterHours(int $hours): self
    {
        $this->failAfterHours = $hours;

        return $this;
    }

    public function run(): Result
    {
        $activeProductCount = Product::query()->where('active', true)->count();

        if ($activeProductCount === 0) {
            return Result::make()
                ->ok('No active products being tracked.')
                ->shortSummary('idle');
        }

        // Count active offers (attached to active products) whose last
        // successful fetch is older than the warning threshold.
        $staleOfferCount = $this->staleOfferQuery($this->warnAfterHours)->count();
        $totalActiveOfferCount = Shop::query()
            ->where('active', true)
            ->where('health', '!=', ShopHealth::Dead->value)
            ->whereHas('product', function (EloquentQueryBuilder $q): void {
                $q->where('active', true);
            })
            ->count();

        $result = Result::make()
            ->meta([
                'active_products' => $activeProductCount,
                'active_offers' => $totalActiveOfferCount,
                'stale_offers' => $staleOfferCount,
                'warn_after_hours' => $this->warnAfterHours,
                'fail_after_hours' => $this->failAfterHours,
            ])
            ->shortSummary("{$staleOfferCount}/{$totalActiveOfferCount} stale");

        if ($staleOfferCount === 0) {
            return $result->ok('All active offers have a recent successful fetch.');
        }

        $criticallyStale = $this->staleOfferQuery($this->failAfterHours)->exists();

        if ($criticallyStale) {
            return $result->failed("{$staleOfferCount} active offer(s) failing or unscraped for over {$this->failAfterHours}h.");
        }

        return $result->warning("{$staleOfferCount} active offer(s) failing or unscraped for over {$this->warnAfterHours}h.");
    }

    /**
     * @return EloquentQueryBuilder<Shop>
     */
    private function staleOfferQuery(int $hours): EloquentQueryBuilder
    {
        return Shop::query()
            ->where('active', true)
            ->where('health', '!=', ShopHealth::Dead->value)
            ->whereHas('product', function (EloquentQueryBuilder $q): void {
                $q->where('active', true);
            })
            ->where(function (EloquentQueryBuilder $q) use ($hours): void {
                $cutoff = now()->subHours($hours);

                // An offer with no successful read at all is judged by the
                // last thing that happened to it — its last attempt, or the
                // edit that gave it this URL. `Shop::updateUrl()` clears both
                // read timestamps on every repoint, and a bare null counts as
                // stale against every threshold, so the check would report an
                // offer as unread for hours it has not existed, at the fail
                // level rather than the warn one.
                $q->where('last_success_at', '<', $cutoff)
                    ->orWhere(function (EloquentQueryBuilder $never) use ($cutoff): void {
                        $never->whereNull('last_success_at')
                            ->whereRaw('coalesce(last_checked_at, updated_at) < ?', [$cutoff]);
                    });
            });
    }
}
