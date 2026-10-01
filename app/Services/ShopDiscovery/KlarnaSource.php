<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Jobs\ReadKlarnaLeads;
use App\Models\Product;
use App\Models\WebDiscovery;
use App\Models\WebShopFinding;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Which Klarna page a product's shop leads come from, and the generation that
 * says so. A new generation starts when the open search is rebuilt or a page
 * is pasted; {@see KlarnaDiscovery} runs the steps of one generation. See
 * specs/klarna-shop-leads.md §5.1 and §7.
 */
final class KlarnaSource
{
    public static function enabled(): bool
    {
        return Config::boolean('dipcatch.web_discovery.klarna_leads');
    }

    /**
     * Starts a new generation: the open search was rebuilt, or a pasted page
     * replaces the source. With a page, that page is the source at once.
     */
    public static function restart(Product $product, ?string $klarnaUrl = null): int
    {
        return DB::transaction(static function () use ($product, $klarnaUrl): int {
            $discovery = WebDiscovery::query()->lockForUpdate()->findOrFail($product->id);
            $generation = $discovery->klarna_generation + 1;
            $discovery->forceFill([
                'klarna_generation' => $generation,
                'klarna_url' => $klarnaUrl,
                'klarna_search_id' => null,
                'klarna_attempts' => 0,
                'klarna_leads' => null,
                'klarna_checked_at' => null,
            ])->save();

            if ($klarnaUrl !== null) {
                self::dropLeadsFromOtherPages($product, $klarnaUrl);
            }

            return $generation;
        });
    }

    /**
     * A rebuilt open search looks for the Klarna page again, unless the
     * Klarna work of a known page, a pasted one say, is still under way.
     */
    public static function searchRebuilt(Product $product, ?WebDiscovery $discovery): void
    {
        if (self::enabled() && ($discovery?->klarna_url === null || ! $discovery->klarnaUnfinished())) {
            self::restart($product);
        }
    }

    /**
     * A pasted Klarna page becomes the source, and its shops are read now.
     * The same page pasted again while it is still being looked up changes
     * nothing. False when the owner pasted their daily share of pages.
     */
    public static function usePasted(Product $product, string $url): bool
    {
        $discovery = WebDiscovery::query()->find($product->id);

        if ($discovery instanceof WebDiscovery && $discovery->klarna_url === $url && $discovery->klarnaUnfinished()) {
            return true;
        }

        // Each pasted page spends searches from the pool every account shares.
        $key = 'klarna-pasted:user:' . $product->user_id;

        if (RateLimiter::tooManyAttempts($key, Config::integer('dipcatch.web_discovery.klarna_pastes_per_day'))) {
            return false;
        }

        RateLimiter::hit($key, decaySeconds: 86_400);

        WebDiscovery::markQueued($product);
        dispatch(new ReadKlarnaLeads((string) $product->id, self::restart($product, $url)));

        return true;
    }

    /** Deletes undismissed lead findings from a page other than `$source`; with no source, all of them. */
    public static function dropLeadsFromOtherPages(Product $product, ?string $source): void
    {
        WebShopFinding::query()
            ->where('product_id', $product->id)
            ->whereNotNull('lead_url')
            ->whereNull('dismissed_at')
            ->when($source !== null, static fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('lead_url', '!=', $source))
            ->delete();
    }
}
