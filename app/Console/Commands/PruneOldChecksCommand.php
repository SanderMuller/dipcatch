<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dipcatch:prune-checks')]
#[Description('Prune price_checks / price_drop_events older than 365 days, keeping at least 50 most-recent rows per product.')]
class PruneOldChecksCommand extends Command
{
    private const int RETAIN_DAYS = 365;

    private const int RETAIN_MIN_PER_PRODUCT = 50;

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETAIN_DAYS);
        $checksDeleted = 0;
        $eventsDeleted = 0;

        Product::query()
            ->select('id')
            ->lazyById(500)
            ->each(function (Product $product) use ($cutoff, &$checksDeleted, &$eventsDeleted): void {
                $eventsDeleted += $this->pruneDropEvents($product->id, $cutoff);
                $checksDeleted += $this->pruneChecks($product->id, $cutoff);
            });

        $this->info("Pruned {$checksDeleted} price_checks and {$eventsDeleted} price_drop_events.");

        return self::SUCCESS;
    }

    private function pruneDropEvents(string $productId, DateTimeInterface $cutoff): int
    {
        $keepIds = PriceDropEvent::query()
            ->where('product_id', $productId)
            ->latest('fired_at')
            ->limit(self::RETAIN_MIN_PER_PRODUCT)
            ->pluck('id')
            ->all();

        $deleted = PriceDropEvent::query()
            ->where('product_id', $productId)
            ->where('fired_at', '<', $cutoff)
            ->whereNotIn('id', $keepIds)
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    private function pruneChecks(string $productId, DateTimeInterface $cutoff): int
    {
        $keepCheckIds = PriceCheck::query()
            ->where('product_id', $productId)
            ->latest('checked_at')
            ->limit(self::RETAIN_MIN_PER_PRODUCT)
            ->pluck('id')
            ->all();

        $referencedByEvents = PriceDropEvent::query()
            ->where('product_id', $productId)
            ->pluck('price_check_id')
            ->all();

        $protected = array_values(array_unique([
            ...array_map(self::stringify(...), $keepCheckIds),
            ...array_map(self::stringify(...), $referencedByEvents),
        ]));

        $deleted = PriceCheck::query()
            ->where('product_id', $productId)
            ->where('checked_at', '<', $cutoff)
            ->whereNotIn('id', $protected)
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    private static function stringify(mixed $id): string
    {
        return is_scalar($id) ? (string) $id : '';
    }
}
