<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\ProUsers;
use App\Jobs\CategoriseExistingProduct;
use App\Models\Product;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\TypeSafeClient;
use App\Services\TypeSafe\TypeSafeRequestFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

#[Signature('dipcatch:categorise-products {--user= : Only this user id} {--limit=200 : Products per run} {--dry-run : Print the verdicts, write nothing}')]
#[Description('Sort the uncategorised products of accounts that opted in to automatic categories.')]
final class CategoriseProductsCommand extends Command
{
    public function handle(TypeSafeClient $client, CategorisationBudget $budget): int
    {
        if (! TypeSafeClient::configured()) {
            $this->error('TYPESAFE_API_KEY is not set; nothing can be categorised.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $userId = $this->option('user');

        $products = Product::query()
            ->with(['shops', 'user'])
            ->whereNull('category')
            ->whereNull('category_set_by')
            // Judged once already; its best guess waits on the edit form.
            ->whereNull('suggested_category')
            // A pre-filter in SQL. Each job, and the dry run, re-checks the
            // row with the one guard the app uses everywhere.
            ->whereIn('user_id', ProUsers::ids())
            ->whereHas('user', fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('auto_categories', true))
            ->when(is_string($userId) && $userId !== '', fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('user_id', $userId))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if (! $dryRun) {
            // A job per product: each asks the AI once, and together they
            // can outlast the App's few awake minutes after a scheduled run.
            foreach ($products as $product) {
                dispatch(new CategoriseExistingProduct($product->id));
            }

            $this->info("Queued {$products->count()} product(s).");

            return self::SUCCESS;
        }

        return $this->dryRun($products, $client, $budget);
    }

    /**
     * Prints each verdict and stores no category, but spends the AI budget.
     * Runs here rather than on the queue, because the output is the point.
     *
     * @param  EloquentCollection<int, Product>  $products
     */
    private function dryRun(EloquentCollection $products, TypeSafeClient $client, CategorisationBudget $budget): int
    {
        $categorised = 0;
        $skipped = 0;
        $failed = 0;
        $inputTokens = 0;
        $outputTokens = 0;

        foreach ($products as $product) {
            if ($product->user?->wantsAutoCategories() !== true || ! $budget->allows($product->user)) {
                $skipped++;

                continue;
            }

            try {
                $verdict = $client->categorise($product);
            } catch (TypeSafeRequestFailed $e) {
                $failed++;
                $this->warn("{$product->title}: {$e->getMessage()}");

                if ($e->isRejectedKey()) {
                    $this->error('TypeSafe rejects TYPESAFE_API_KEY in this environment. Check the value, then redeploy so the cached config picks it up.');

                    return self::FAILURE;
                }

                continue;
            }

            $inputTokens += $verdict->inputTokens;
            $outputTokens += $verdict->outputTokens;

            $this->line(sprintf(
                '%s | winner %s | path %.3f | runner-up %s | separation %s | %s',
                $product->title,
                $verdict->winner->value ?? '-',
                $verdict->pathScore,
                $verdict->runnerUp->value ?? '-',
                is_infinite($verdict->separation) ? 'inf' : number_format($verdict->separation, 2),
                $verdict->stored() ? 'stored' : 'below the guards',
            ));

            if ($verdict->category === null) {
                $skipped++;
            } else {
                $categorised++;
            }
        }

        $this->info(sprintf(
            '[dry run] %d categorised, %d skipped, %d failed. Tokens: %d in, %d out.',
            $categorised,
            $skipped,
            $failed,
            $inputTokens,
            $outputTokens,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
