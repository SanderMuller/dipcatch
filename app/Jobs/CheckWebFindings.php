<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Models\WebDiscovery;
use App\Services\ShopDiscovery\WebSecondCheck;
use App\Services\ShopDiscovery\WebShopDiscovery;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * The second Jev check for a product's read pages. Unique only until it
 * starts: a page read while it waits in the queue is checked in the same
 * request, and a page read after it starts queues the next check.
 */
#[Tries(1)]
#[Timeout(75)]
final class CheckWebFindings implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const int GATHER_SECONDS = 2;

    /** @param  bool  $early  A check that does not wait for the other reads, counted per product. */
    public function __construct(public string $productId, public bool $early = false) {}

    public function uniqueId(): string
    {
        return "check-web-findings:{$this->productId}";
    }

    /** Frees the lock should the queued check be lost. */
    public function uniqueFor(): int
    {
        return 60;
    }

    public function handle(WebSecondCheck $secondCheck): void
    {
        $product = Product::query()->with(['user', 'shops'])->find($this->productId);

        if ($product instanceof Product && (! $this->early || WebShopDiscovery::startsEarlyCheck($product))) {
            $secondCheck->check($product);
        }
    }

    public function failed(): void
    {
        $product = Product::query()->with(['user', 'shops'])->find($this->productId);

        if ($product instanceof Product) {
            WebSecondCheck::releaseStaleClaims($product);
            WebDiscovery::finishIfDone($product);
        }
    }
}
