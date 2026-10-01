<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Services\ShopDiscovery\KlarnaDiscovery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Finds the Klarna page that lists a product's shops: one search and one
 * same-product check at most. See specs/klarna-shop-leads.md §5.1.
 */
#[Tries(1)]
#[Timeout(75)]
final class FindKlarnaPage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public string $productId, public int $generation) {}

    public function uniqueId(): string
    {
        return "find-klarna-page:{$this->productId}:{$this->generation}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(KlarnaDiscovery $klarna): void
    {
        $product = Product::query()->with(['user', 'shops'])->find($this->productId);

        if ($product instanceof Product) {
            $klarna->findPage($product, $this->generation);
        }
    }

    public function failed(): void
    {
        $product = Product::query()->find($this->productId);

        if ($product instanceof Product) {
            app(KlarnaDiscovery::class)->countAttempt($product, $this->generation);
        }
    }
}
