<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Services\ShopDiscovery\BarcodeSearch;
use App\Services\ShopDiscovery\WebShopDiscovery;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;

/**
 * Searches a product's barcode for web discovery ({@see BarcodeSearch}), then
 * queues discovery again to store and check what it found. Without a search,
 * discovery is not queued, so a spent daily limit cannot loop the two jobs.
 *
 * While it is queued the product's discovery is not done
 * ({@see WebDiscovery::finishIfDone()}), so an open suggestions panel keeps
 * polling for what it finds.
 */
#[Tries(1)]
// The query lock's 10 s wait, Serper's 20 s timeout, and room to store.
#[Timeout(45)]
final class SearchProductBarcode implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const int PENDING_SECONDS = 600;

    public function __construct(public string $productId) {}

    public static function queueFor(Product $product): void
    {
        Cache::put(self::pendingKey((string) $product->id), true, self::PENDING_SECONDS);
        dispatch(new self((string) $product->id));
    }

    public static function isPending(Product $product): bool
    {
        return Cache::has(self::pendingKey((string) $product->id));
    }

    public function uniqueId(): string
    {
        return "search-product-barcode:{$this->productId}";
    }

    public function uniqueFor(): int
    {
        return self::PENDING_SECONDS;
    }

    public function handle(BarcodeSearch $barcodes, WebShopDiscovery $discovery): void
    {
        $product = Product::query()->with(['user', 'shops'])->find($this->productId);

        if (! $product instanceof Product) {
            return;
        }

        // The owner can lose the gate while this waits; a search is paid.
        $searched = $product->user instanceof User
            && $discovery->runsForOwner($product->user, $product->currency)
            && $barcodes->search($product);

        Cache::forget(self::pendingKey($this->productId));

        if ($searched) {
            $discovery->queue($product);
        } else {
            WebDiscovery::finishIfDone($product);
        }
    }

    public function failed(): void
    {
        Cache::forget(self::pendingKey($this->productId));
        $product = Product::query()->find($this->productId);

        if ($product instanceof Product) {
            WebDiscovery::finishIfDone($product);
        }
    }

    private static function pendingKey(string $productId): string
    {
        return "web-discovery:barcode-pending:{$productId}";
    }
}
