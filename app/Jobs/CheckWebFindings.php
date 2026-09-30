<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Models\WebDiscovery;
use App\Services\ShopDiscovery\WebSecondCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * The second Jev check for a product's read pages. Not unique: a uniqueness
 * lock would swallow a later dispatch.
 */
#[Tries(1)]
#[Timeout(75)]
final class CheckWebFindings implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $productId) {}

    public function handle(WebSecondCheck $secondCheck): void
    {
        $product = Product::query()->with(['user', 'shops'])->find($this->productId);

        if ($product instanceof Product) {
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
