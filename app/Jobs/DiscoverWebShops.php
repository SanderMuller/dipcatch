<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Models\WebDiscovery;
use App\Services\ShopDiscovery\WebShopDiscovery;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Searches the web for more shops that sell a product and runs the first Jev
 * check. The page reads and the second check are jobs of their own, so no
 * one job outruns the queue's `retry_after`.
 */
#[Tries(1)]
#[Timeout(75)]
final class DiscoverWebShops implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(public string $productId) {}

    /**
     * Unique while queued only: a product changed during a run queues the
     * next run instead of being swallowed by the running one's lock. Every
     * step checks the findings' state before it writes, so a second run
     * does no harm.
     */
    public function uniqueId(): string
    {
        return "discover-web-shops:{$this->productId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(WebShopDiscovery $discovery): void
    {
        $product = Product::query()->with(['user', 'shops'])->find($this->productId);

        if ($product instanceof Product) {
            $discovery->discover($product);
        }
    }

    public function failed(): void
    {
        $product = Product::query()->find($this->productId);

        if ($product instanceof Product) {
            WebDiscovery::finishIfDone($product);
        }
    }
}
