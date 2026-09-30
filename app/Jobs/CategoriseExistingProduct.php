<?php declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Products\CategoriseProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Sorts one product someone already tracked when they switched automatic
 * categories on. A queued job rather than the after-response hook a new
 * product uses: there can be dozens, and each asks the AI on its own.
 */
#[Tries(1)]
#[Timeout(60)]
final class CategoriseExistingProduct implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public string $productId) {}

    public function uniqueId(): string
    {
        return "categorise-existing-product:{$this->productId}";
    }

    /**
     * The products of an account that still need a category and were never
     * judged, up to the account's daily AI budget; the nightly run sorts the
     * rest. Returns how many were queued.
     */
    public static function queueFor(User $user): int
    {
        if (! TypeSafeClient::configured() || ! $user->wantsAutoCategories()) {
            return 0;
        }

        $limit = config()->integer('dipcatch.categories.daily_limit_per_user');
        $products = Product::query()
            ->where('user_id', $user->id)
            ->whereNull('category')
            ->whereNull('category_set_by')
            ->whereNull('suggested_category')
            ->oldest()
            ->when($limit > 0, fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->limit($limit))
            ->get(['id']);

        foreach ($products as $product) {
            dispatch(new self($product->id));
        }

        return $products->count();
    }

    public function handle(TypeSafeClient $client, CategorisationBudget $budget): void
    {
        $product = Product::query()->with('user')->find($this->productId);

        // Judged or chosen meanwhile, switched off again, or over budget: the
        // nightly run or the edit form picks it up.
        if (! $product instanceof Product || $product->category !== null || $product->category_set_by !== null || $product->suggested_category !== null) {
            return;
        }

        if ($product->user?->wantsAutoCategories() !== true || ! $budget->allows($product->user)) {
            return;
        }

        new CategoriseProduct($product->id)->handle($client);
    }
}
