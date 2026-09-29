<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Products\BorrowShopImage;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;

#[Signature('dipcatch:backfill-product-images {--dry-run : Count the products, write nothing}')]
#[Description('Give every product without an image the image of its first shop that has one.')]
final class BackfillProductImagesCommand extends Command
{
    public function handle(BorrowShopImage $borrow): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $filled = 0;

        Product::query()
            ->whereHas('shops', fn (EloquentQueryBuilder $shop): EloquentQueryBuilder => $shop->whereNotNull('image_url'))
            ->with('shops')
            ->lazyById()
            ->each(function (Product $product) use ($borrow, $dryRun, &$filled): void {
                // A stored value the app would refuse to show counts as none.
                if ($product->safeImageUrl() !== null) {
                    return;
                }

                // Oldest first: "the first shop" is the one added first.
                $shop = $product->shops
                    ->sortBy([['created_at', 'asc'], ['id', 'asc']])
                    ->first(fn (Shop $shop): bool => $shop->safeImageUrl() !== null);

                if ($shop === null) {
                    return;
                }

                if ($dryRun || $borrow($product, $shop)) {
                    $filled++;
                }
            });

        $this->info($dryRun
            ? "[dry run] {$filled} product(s) would get an image."
            : "{$filled} product(s) got an image.");

        return self::SUCCESS;
    }
}
