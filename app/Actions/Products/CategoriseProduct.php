<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Enums\CategorySource;
use App\Models\Product;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\CategoryVerdict;
use App\Services\TypeSafe\TypeSafeClient;
use App\Services\TypeSafe\TypeSafeRequestFailed;
use Illuminate\Support\Facades\Log;

/**
 * Sorts one freshly created product into a category after the response.
 *
 * Registered through `app()->terminating()`, never `dispatch()->afterResponse()`:
 * the bus dispatcher registers on the container it was built with, which
 * under Octane can be the base application whose terminate never runs.
 */
final class CategoriseProduct
{
    public function __construct(public string $productId) {}

    public static function afterResponseFor(Product $product): void
    {
        if ($product->category !== null || ! TypeSafeClient::configured()) {
            return;
        }

        if ($product->user?->wantsAutoCategories() !== true) {
            return;
        }

        if (! app(CategorisationBudget::class)->allows($product->user)) {
            Log::info('Automatic categorisation skipped: the daily budget is spent.', ['product_id' => $product->id]);

            return;
        }

        $job = new self($product->id);

        app()->terminating(static function () use ($job): void {
            $job->handle(app(TypeSafeClient::class));
        });
    }

    public function handle(TypeSafeClient $client): void
    {
        $product = Product::query()->with(['shops', 'user'])->find($this->productId);

        if (! $product instanceof Product || $product->category_set_by !== null) {
            return;
        }

        if ($product->user?->wantsAutoCategories() !== true) {
            return;
        }

        try {
            $verdict = $client->categorise($product);
        } catch (TypeSafeRequestFailed $e) {
            Log::warning('Automatic categorisation failed; the product stays uncategorised.', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return;
        }

        if ($verdict->category === null) {
            self::store($product, $verdict);

            Log::info('Automatic categorisation was not confident enough to store a category.', [
                'product_id' => $product->id,
                'winner' => $verdict->winner?->value,
                'path_score' => $verdict->pathScore,
                'separation' => $verdict->separation,
            ]);

            return;
        }

        if (! self::store($product, $verdict)) {
            Log::info('Automatic categorisation skipped a product whose category was set in the meantime.', [
                'product_id' => $product->id,
            ]);
        }
    }

    /**
     * Writes a confident verdict as the automatic category, or keeps a
     * below-guard winner as the suggestion the edit form offers. Both are
     * conditional on the source still being null, so an edit saved since the
     * caller's read wins. Returns whether a category was written.
     */
    public static function store(Product $product, CategoryVerdict $verdict): bool
    {
        if ($verdict->category === null) {
            if ($verdict->winner !== null) {
                Product::query()
                    ->whereKey($product->id)
                    ->whereNull('category_set_by')
                    ->update(['suggested_category' => $verdict->winner->value]);
            }

            return false;
        }

        return Product::query()
            ->whereKey($product->id)
            ->whereNull('category_set_by')
            ->update([
                'category' => $verdict->category->value,
                'category_set_by' => CategorySource::Auto->value,
                'suggested_category' => null,
            ]) === 1;
    }
}
