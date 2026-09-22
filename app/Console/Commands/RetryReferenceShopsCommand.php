<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\ShopDraft;
use App\Enums\ShopKind;
use App\Models\Shop;
use App\Support\Config as DipConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the shops kept as links whether they will talk yet.
 *
 * A block is a fact about today. WAF rules change, fingerprints shift, shops
 * replatform, and this app's own fetcher improves — dierapotheker.nl spent a
 * day on a blocked list while the real fault was a trailing slash DipCatch was
 * stripping, and it took a person noticing that nine failures against five
 * successes could not be chance. A weekly pass would have found it with nobody
 * looking.
 *
 * That is what makes the list of blocked shops worth keeping at all. Without
 * this it is a record of apologies; with it, every URL ever refused is a
 * standing claim on a future the fetcher gets better at, and coverage only
 * moves one way.
 */
#[Signature('dipcatch:retry-reference-shops {--limit=50 : Links per run} {--dry-run : Report what would be tried, fetch nothing}')]
#[Description('Retry the shops kept as links, and start tracking any whose page has become readable.')]
final class RetryReferenceShopsCommand extends Command
{
    /** How long a link waits between attempts. */
    private const int EVERY_DAYS = 7;

    public function handle(ProbeShopUrl $probe, AttachShop $attach): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $due = $this->dueQuery()->limit(max(1, (int) $this->option('limit')))->get();

        if ($due->isEmpty()) {
            $this->info('No links are due a retry.');

            return self::SUCCESS;
        }

        $promoted = 0;

        foreach ($due as $shop) {
            if ($dryRun) {
                $this->line("would retry {$shop->url}");

                continue;
            }

            $promoted += $this->retry($shop, $probe, $attach) ? 1 : 0;
        }

        $this->info(sprintf('Retried %d link(s); %d now read and are tracked.', $dryRun ? 0 : $due->count(), $promoted));

        return self::SUCCESS;
    }

    /**
     * One link, asked again.
     *
     * A refusal costs the row nothing but a stamp: its failure counters stay
     * at zero, because a link that cannot be read is not a shop going wrong —
     * it is a shop behaving exactly as recorded. Counting these would reach
     * `dead_after` and switch the feature off from the inside.
     */
    private function retry(Shop $shop, ProbeShopUrl $probe, AttachShop $attach): bool
    {
        $product = $shop->product;
        $owner = $product?->user;

        if ($product === null || $owner === null) {
            return false;
        }

        // Never on the caller's budget: nobody asked for this.
        $outcome = $probe(null, $shop->url, $owner, spendBudget: false);

        $shop->forceFill(['retried_at' => now()])->save();

        if (! $outcome->isSuccess()) {
            return false;
        }

        try {
            // The row is replaced rather than filled in: `AttachShop` writes
            // the first price check and the recompute that reads it, which is
            // how every other shop starts life. A link carries no history to
            // lose — no checks, no cheapest segments, no drop events — so
            // there is nothing to preserve and one fewer path to maintain.
            $shop->delete();

            $attach->firstShopOf($product, ShopDraft::fromSnapshot(
                ShopDraft::flatten($outcome),
                $outcome->normalizedUrl ?? $shop->url,
                (string) $outcome->adapterKey,
            ));
        } catch (Throwable $e) {
            Log::warning('A shop kept as a link became readable but could not be tracked.', [
                'shop_url' => $shop->url,
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $this->line("now readable: {$shop->url}");

        return true;
    }

    /**
     * @return EloquentQueryBuilder<Shop>
     */
    private function dueQuery(): EloquentQueryBuilder
    {
        $cutoff = now()->subDays(DipConfig::int('dipcatch.reference.retry_every_days', self::EVERY_DAYS));

        return Shop::query()
            ->where('kind', ShopKind::Reference->value)
            ->where('active', true)
            ->whereHas('product', fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('active', true))
            ->where(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query
                ->whereNull('retried_at')
                ->orWhere('retried_at', '<', $cutoff))
            ->orderByRaw('retried_at IS NULL DESC')
            ->oldest('retried_at');
    }
}
