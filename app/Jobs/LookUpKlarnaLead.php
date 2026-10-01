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
 * Looks one Klarna lead up on its shop's own site: one search. The lead's
 * title and size are read back from the stored list, so a lost job is
 * dispatched again from it. See specs/klarna-shop-leads.md §5.2.
 */
#[Tries(1)]
#[Timeout(45)]
final class LookUpKlarnaLead implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public string $productId, public int $generation, public string $host) {}

    public function uniqueId(): string
    {
        return "look-up-klarna-lead:{$this->productId}:{$this->generation}:{$this->host}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(KlarnaDiscovery $klarna): void
    {
        $product = Product::query()->with(['user', 'shops'])->find($this->productId);

        if ($product instanceof Product) {
            $klarna->lookUp($product, $this->generation, $this->host);
        }
    }

    public function failed(): void
    {
        $product = Product::query()->find($this->productId);

        if ($product instanceof Product) {
            app(KlarnaDiscovery::class)->countLookUpAttempt($product, $this->generation, $this->host);
        }
    }
}
