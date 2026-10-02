<?php declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\ShopDraft;
use App\Enums\ShopKind;
use App\Models\Shop;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One shop kept as a link, asked again whether it will talk.
 *
 * A refusal costs the row nothing but a stamp: its failure counters stay
 * at zero, because a link that cannot be read is not a shop going wrong —
 * it is a shop behaving exactly as recorded. Counting these would reach
 * `dead_after` and switch the feature off from the inside.
 */
#[Tries(1)]
#[Timeout(75)]
final class RetryReferenceShop implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public string $shopId) {}

    public function uniqueId(): string
    {
        return "retry-reference-shop:{$this->shopId}";
    }

    /** Frees the lock should the queued retry be lost. */
    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(ProbeShopUrl $probe, AttachShop $attach): void
    {
        $shop = Shop::query()->with('product.user')->find($this->shopId);

        // Promoted by a delivery before this one, or changed since it was
        // queued.
        if (! $shop instanceof Shop || $shop->kind !== ShopKind::Reference || ! $shop->active) {
            return;
        }

        $product = $shop->product;
        $owner = $product?->user;

        if ($product === null || $owner === null) {
            return;
        }

        // Never on the caller's budget: nobody asked for this.
        $outcome = $probe(null, $shop->url, $owner, spendBudget: false);

        $shop->forceFill(['retried_at' => now()])->save();

        if (! $outcome->isSuccess()) {
            return;
        }

        try {
            // The row is replaced rather than filled in: `AttachShop` writes
            // the first price check and the recompute that reads it, which is
            // how every other shop starts life. A link carries no history to
            // lose — no checks, no cheapest segments, no drop events — so
            // there is nothing to preserve and one fewer path to maintain.
            // One transaction, so a failure or a stopped worker between the
            // two steps keeps the link.
            DB::transaction(function () use ($shop, $product, $outcome, $attach): void {
                $shop->delete();

                $attach->firstShopOf($product, ShopDraft::fromOutcome(
                    $outcome,
                    $outcome->normalizedUrl ?? $shop->url,
                    (string) $outcome->adapterKey,
                ));
            });
        } catch (Throwable $e) {
            Log::warning('A shop kept as a link became readable but could not be tracked.', [
                'shop_url' => $shop->url,
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Log::info('A shop kept as a link is readable now and is tracked.', ['shop_url' => $shop->url]);
    }
}
