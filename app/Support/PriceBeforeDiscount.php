<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ProductCategory;
use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Checks a shop's claimed "was" price against its own readings: the EU rule
 * says a discount must be measured from the shop's lowest price in the 30
 * days before it. See specs/category-expansion.md, section 4.
 *
 * Only readings that can stand as evidence count: successful, from a reader
 * that can state a claim, with prices read on the page rather than carried
 * over, a consumer price, and today's seller. Any other reading is a gap.
 */
final class PriceBeforeDiscount
{
    private const int WINDOW_DAYS = 30;

    private const int RUN_MONTHS = 3;

    private const int MAX_GAP_SECONDS = 3 * 86_400;

    /**
     * ACM's exception for perishable goods lets them claim the price just
     * before the discount. A category is a heuristic for that, not the rule.
     */
    private const array PERISHABLE = [
        ProductCategory::FreshProduce,
        ProductCategory::MeatFishVeg,
        ProductCategory::DairyEggs,
        ProductCategory::Bakery,
    ];

    /**
     * One result per shop that has something to show, in one query.
     *
     * @param  Collection<int, Shop>  $shops
     * @return array<string, DiscountCheck>
     */
    public static function forShops(Product $product, Collection $shops): array
    {
        $claiming = $shops->filter(static fn (Shop $shop): bool => ! $shop->isReference() && $shop->claimed_regular_price !== null);

        if ($claiming->isEmpty() || in_array($product->category, self::PERISHABLE, strict: true)) {
            return [];
        }

        $now = CarbonImmutable::now();
        $earliest = $now->subMonthsNoOverflow(self::RUN_MONTHS)->subDays(self::WINDOW_DAYS)->subSeconds(self::MAX_GAP_SECONDS);

        $readings = PriceCheck::query()
            ->whereIn('shop_id', $claiming->pluck('id'))
            ->where('status', ScrapeStatus::Ok)
            ->where('checked_at', '>=', $earliest)
            ->oldest('checked_at')
            ->orderBy('id')
            ->get(['id', 'shop_id', 'price', 'single_item_price', 'checked_at', 'claimed_regular_price', 'seller', 'claim_read', 'shelf_inherited', 'consumer_price_issue']);

        $results = [];

        foreach ($claiming as $shop) {
            $check = self::check($shop, $readings->where('shop_id', $shop->id)->values(), $now);

            if ($check instanceof DiscountCheck) {
                $results[$shop->id] = $check;
            }
        }

        return $results;
    }

    /**
     * @param  Collection<int, PriceCheck>  $readings  Oldest first.
     */
    private static function check(Shop $shop, Collection $readings, CarbonImmutable $now): ?DiscountCheck
    {
        if ($shop->repointed_at !== null) {
            $readings = $readings->filter(static fn (PriceCheck $reading): bool => $reading->checked_at->greaterThan($shop->repointed_at));
        }

        $today = $readings->last();
        $claim = $today?->claimed_regular_price;

        if (! $today instanceof PriceCheck || ! self::isEvidence($today, $today->seller) || ! is_numeric($claim)) {
            return null;
        }

        $evidence = $readings->filter(static fn (PriceCheck $reading): bool => self::isEvidence($reading, $today->seller))->values()->all();

        if ($evidence === []) {
            return null;
        }
        $runStart = self::runStart($evidence, $claim);
        $cap = $now->subMonthsNoOverflow(self::RUN_MONTHS);
        // A run past ACM's three months has outlived the progressive-discount
        // exception, so its window becomes the 30 days before now.
        $overCap = $runStart->lessThan($cap);
        $windowEnd = $overCap ? $now : $runStart;
        $windowStart = $windowEnd->subDays(self::WINDOW_DAYS);

        // For a long run, coverage also spans back to the cap: a gap there
        // could hide a rise that starts the run inside the three months. A
        // gap older than the cap cannot, so history before it is not needed.
        if (! self::covered($evidence, $overCap ? $windowStart->min($cap) : $windowStart, $now)) {
            return null;
        }

        $low = null;

        foreach ($evidence as $reading) {
            $shelf = self::shelfPrice($reading);

            if ($shelf !== null && $reading->checked_at->greaterThanOrEqualTo($windowStart) && $reading->checked_at->lessThan($windowEnd)
                && ($low === null || bccomp($shelf, $low, 2) === -1)) {
                $low = $shelf;
            }
        }

        if ($low === null || bccomp($claim, $low, 2) !== 1) {
            return null;
        }

        return new DiscountCheck($claim, $low, (string) $shop->currency);
    }

    /**
     * The first reading of the unbroken run that shows today's claim and only
     * falls: a progressive discount keeps one claim across its steps.
     *
     * @param  non-empty-list<PriceCheck>  $evidence  Oldest first, ending at today.
     */
    private static function runStart(array $evidence, string $claim): CarbonImmutable
    {
        $first = $evidence[count($evidence) - 1];

        for ($index = count($evidence) - 2; $index >= 0; $index--) {
            $earlier = $evidence[$index];

            if ($earlier->claimed_regular_price !== $claim || bccomp(self::shelfPrice($earlier) ?? '0', self::shelfPrice($first) ?? '0', 2) === -1) {
                break;
            }

            $first = $earlier;
        }

        return $first->checked_at->toImmutable();
    }

    /**
     * Whether the readings leave no gap over three days from the last one at
     * or before `$from` to the moment the page is shown.
     *
     * @param  list<PriceCheck>  $evidence
     */
    private static function covered(array $evidence, CarbonImmutable $from, CarbonImmutable $now): bool
    {
        $previous = null;

        foreach ($evidence as $reading) {
            $at = $reading->checked_at->toImmutable();

            if ($at->lessThanOrEqualTo($from)) {
                $previous = $at;

                continue;
            }

            if ($previous === null || $previous->diffInSeconds($at) > self::MAX_GAP_SECONDS) {
                return false;
            }

            $previous = $at;
        }

        return $previous !== null && $previous->diffInSeconds($now) <= self::MAX_GAP_SECONDS;
    }

    private static function isEvidence(PriceCheck $reading, ?string $seller): bool
    {
        return $reading->claim_read === true
            && $reading->shelf_inherited === false
            && $reading->consumer_price_issue === null
            && $reading->seller === $seller;
    }

    /** @return numeric-string|null */
    private static function shelfPrice(PriceCheck $reading): ?string
    {
        $price = $reading->single_item_price ?? $reading->price;

        return is_numeric($price) ? $price : null;
    }
}
