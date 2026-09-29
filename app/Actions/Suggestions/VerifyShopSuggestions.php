<?php declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Models\Product;
use App\Models\ShopSuggestionVerdict;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\ShopMatchCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Asks Jev, after the response, whether the suggested rows a product has no
 * answer for yet sell the same product and pack, and stores each answer. The
 * next render of the suggestions reads them.
 *
 * Registered through `app()->terminating()` for the reason `CategoriseProduct`
 * gives, and at most once per product per ten minutes, because the product
 * page renders the suggestions twice and a person can reload it.
 */
final readonly class VerifyShopSuggestions
{
    /**
     * @param  list<UncheckedSuggestion>  $unchecked
     */
    public static function afterResponseFor(Product $product, array $unchecked): void
    {
        if (! Cache::add("shop-suggestions:verify:{$product->id}", true, now()->addMinutes(10))) {
            return;
        }

        $batch = array_slice(self::chainsFirst($unchecked), 0, max(1, Config::integer('dipcatch.shop_checks.max_candidates')));
        $productId = $product->id;

        app()->terminating(static function () use ($productId, $batch): void {
            app(self::class)->handle($productId, $batch);
        });
    }

    public function __construct(private ShopMatchCheck $shopMatch) {}

    /**
     * Best first, but each chain's best row before any chain's second: a
     * chain shows one row, so its runner-up only matters once Jev rejects
     * the first.
     *
     * @param  list<UncheckedSuggestion>  $unchecked
     * @return list<UncheckedSuggestion>
     */
    private static function chainsFirst(array $unchecked): array
    {
        usort($unchecked, static fn (UncheckedSuggestion $a, UncheckedSuggestion $b): int => $b->score <=> $a->score);

        $leaders = [];
        $rest = [];

        foreach ($unchecked as $row) {
            if (isset($leaders[$row->chain])) {
                $rest[] = $row;
            } else {
                $leaders[$row->chain] = $row;
            }
        }

        return [...array_values($leaders), ...$rest];
    }

    /**
     * @param  list<UncheckedSuggestion>  $batch
     */
    public function handle(string $productId, array $batch): void
    {
        $product = Product::query()->with(['shops', 'user'])->find($productId);

        if (! $product instanceof Product || ! $this->shopMatch->applies($product)) {
            return;
        }

        $candidates = [];

        foreach ($batch as $index => $row) {
            $candidates["c{$index}"] = ShopMatchCheck::candidate(
                shop: $row->chainLabel,
                title: $row->name,
                packSize: $row->size,
                price: "{$row->price} EUR",
            );
        }

        foreach ($this->shopMatch->ask($product, ShopCheckPurpose::Suggestions, $candidates) as $key => $chance) {
            $row = $batch[(int) substr($key, 1)] ?? null;

            if (! $row instanceof UncheckedSuggestion) {
                continue;
            }

            ShopSuggestionVerdict::query()->upsert(
                [[
                    'product_id' => $product->id,
                    'chain' => $row->chain,
                    'external_id' => $row->externalId,
                    'fingerprint' => SuggestionVerdicts::fingerprint(SuggestionVerdicts::evidence($product), $row->name, $row->size),
                    'same_chance' => $chance,
                    'checked_at' => now(),
                ]],
                ['product_id', 'chain', 'external_id'],
                ['fingerprint', 'same_chance', 'checked_at'],
            );
        }
    }
}
