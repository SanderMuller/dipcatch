<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * The user's other products already tracking this page.
 *
 * Adding a shop checks for a duplicate only inside the product being added to
 * ({@see ProbeShopUrl}, and the `(product_id, url_hash)` unique key says the
 * same). Across products it is permitted on purpose — a variant page really
 * does serve several products — so nothing said anything, and one account ended
 * up with two "Fanta Cassis 1,5 L" products on one URL, both alerting on the
 * same fall.
 *
 * A note rather than a refusal. The legitimate case is real, and the caller is
 * the one who knows which this is.
 */
final readonly class TrackedElsewhere
{
    /**
     * Titles of this user's other products tracking the same page in the same
     * way, newest first.
     *
     * The variant matters: the same URL under two different `variant_key`
     * values is two different things to buy, which is exactly what that column
     * is for. Only an identical variant is a duplicate.
     *
     * Scoped to one user always. Another account's product titles are not this
     * caller's to see.
     *
     * @return list<string>
     */
    public static function productTitles(
        mixed $userId,
        ?string $normalizedUrl,
        ?string $variantKey,
        mixed $excludeProductId = null,
    ): array {
        if (! is_string($normalizedUrl) || $normalizedUrl === '') {
            return [];
        }

        return Product::query()
            ->where('user_id', $userId)
            ->when(
                is_string($excludeProductId) && $excludeProductId !== '',
                fn (Builder $query): Builder => $query->whereKeyNot($excludeProductId),
            )
            ->whereHas('shops', fn (Builder $shops): Builder => $shops
                ->where('url', $normalizedUrl)
                ->when(
                    $variantKey === null,
                    fn (Builder $q): Builder => $q->whereNull('variant_key'),
                    fn (Builder $q): Builder => $q->where('variant_key', $variantKey),
                ))
            ->latest('id')
            ->get()
            ->map(fn (Product $product): string => $product->title)
            ->values()
            ->all();
    }

    /**
     * One sentence for a preview, or null when the page is not tracked
     * elsewhere.
     *
     * @param  list<string>  $titles
     */
    public static function note(array $titles): ?string
    {
        if ($titles === []) {
            return null;
        }

        return sprintf(
            'This page is already tracked on %s. Both products would alert on the same price. Add it here only if these are genuinely different things to buy — a page with several variants needs a different variant_key for each.',
            '"' . implode('", "', $titles) . '"',
        );
    }

    /** Whether this shop row is the same page and variant as another product's. */
    public static function matches(Shop $shop, ?string $normalizedUrl, ?string $variantKey): bool
    {
        return $shop->url === $normalizedUrl && $shop->variant_key === $variantKey;
    }
}
