<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PackExclusion;
use App\Jobs\ConfirmAltPackSize;
use App\Models\Shop;
use Illuminate\Support\Collection;

/**
 * Resolves, for one product at a time, which of its shops can be compared per
 * unit and on what size.
 *
 * A product-level read rather than a per-shop accessor, because the answer for
 * one shop depends on what its siblings say: a shop stating nothing inherits a
 * size only when every stating sibling agrees, and the plausibility guard needs
 * the field's median to test against.
 *
 * A shop can state its pack twice, in two units — AH lists Iglo fish fingers
 * as 20 pieces and as 560 g — and the second size is kept apart from the pack
 * columns ({@see AltPackSize}). Either one is the shop's own word, so a shop
 * compared on its second size is `Stated`, not derived. What is never done is
 * borrowing: a row that says only `12 piece` is not given a weight by dividing
 * a sibling's grams by its count, because that returns the sibling's weight
 * and hides an assumption a 6-pack listed beside 12-packs makes
 * catastrophically wrong.
 */
final readonly class ComparablePacks
{
    /**
     * How far from the stating shops' median unit price an inherited size, or
     * a shop's second size, may land before it reads as a wrong size rather
     * than a real offer.
     *
     * Tested in both directions. It used to refuse only the implausibly cheap,
     * reasoning that a row stamped too expensive loses nothing a reader acts on
     * — an inferred size can neither win nor alert. That was wrong about what a
     * reader acts on. A 150-tablet pack that inherited a sibling's 75 showed
     * 0.1265 a tablet against a true 0.0633, so the best deal on the product
     * was displayed as the worst. It could not win, and nobody needed it to in
     * order to be misled by it. Reported 2026-09-22.
     *
     * One ratio rather than two constants, so the two sides of the band cannot
     * drift apart.
     */
    private const float IMPLAUSIBLE_RATIO = 0.60;

    /**
     * @param  array<string, ComparablePack>  $packs  keyed by shop id
     * @param  array<string, true>  $altsInDoubt  keyed by shop id
     */
    private function __construct(
        private ?string $unit,
        private array $packs,
        private ItemSizes $itemSizes,
        private ?PackSize $agreed = null,
        private array $altsInDoubt = [],
    ) {}

    /**
     * `$shops` is every shop the answer has to cover, including the ones a page
     * shows greyed out. `$voters` is the subset allowed to *decide* the
     * product's unit, the size its silent siblings inherit, and the median an
     * implausible size is tested against — the live, sellable shops.
     *
     * The two differ on purpose. Two deactivated shops measured in millilitres
     * must not outvote the one live shop measured in grams: that would make the
     * live shop "measured in a different unit", leave the product with no
     * best-value winner, and switch drop detection off for it entirely. A stale
     * price must not move the plausibility median either.
     *
     * `$voters` must be a subset of `$shops`. The guarantee that a resolved
     * unit always has a shop that can win in it rests on it: the unit is the
     * one most voters have a size in — their own, or a second size that holds
     * — so one of them is `Stated` and winnable, but only if that voter is also
     * a shop this resolver answers for. `$currentUnit`, the unit the product
     * compares in now, breaks a tie.
     *
     * @param  Collection<int, Shop>  $shops
     * @param  Collection<int, Shop>|null  $voters  a subset of `$shops`; defaults to all of them
     */
    public static function of(Collection $shops, string $currency, ?Collection $voters = null, ?string $currentUnit = null): self
    {
        $voters = ($voters ?? $shops)->filter(fn (Shop $shop): bool => $shop->currency === $currency);

        $primaries = $voters->map(fn (Shop $shop): ?PackSize => self::statedSize($shop))->filter();
        $alts = $voters->map(fn (Shop $shop): ?PackSize => self::usableAlt($shop))->filter();

        $vote = UnitVote::tally(
            $voters,
            $primaries,
            $alts,
            fn (Collection $inUnit): ?float => self::medianUnitPrice($voters, $inUnit),
            self::altHolds(...),
        );
        $medians = $vote->medians;
        $altsInDoubt = $vote->altsInDoubt;
        $unit = $vote->winner($primaries, $alts->isNotEmpty() ? $currentUnit : null);

        $itemSizes = ItemSizes::of($voters, fn (Shop $shop, PackSize $alt): bool => self::altHolds($shop, $alt, $medians[$alt->unit] ?? null));

        if ($unit === null) {
            return new self(unit: null, packs: [], itemSizes: $itemSizes, altsInDoubt: $altsInDoubt);
        }

        $median = $medians[$unit] ?? null;
        $used = $voters->map(fn (Shop $shop): ?PackSize => self::sizeIn($shop, $unit, $median))->filter();
        $agreed = self::agreedSize($used);

        $packs = [];

        foreach ($shops as $shop) {
            $packs[(string) $shop->id] = self::resolveOne($shop, $currency, $unit, $agreed, $median, $itemSizes);
        }

        return new self($unit, $packs, $itemSizes, $agreed, $altsInDoubt);
    }

    /**
     * How much one piece weighs or holds, in g or ml, as the shops that state
     * both a count and a weight or volume agree on it. See {@see ItemSizes}.
     */
    public function itemSizeBetween(string $from, string $to): ?float
    {
        return $this->itemSizes->between($from, $to);
    }

    /**
     * The shops whose item size sits outside the others' and that Jev has not
     * answered about. See {@see ItemSizes}.
     *
     * @return list<string> shop ids
     */
    public function itemSizeOutliers(string $from, string $to): array
    {
        return $this->itemSizes->outliers($from, $to);
    }

    /** Whether this shop's second size failed the plausibility guard in its own unit, with no answer from Jev yet. */
    public function altInDoubt(Shop $shop): bool
    {
        return isset($this->altsInDoubt[(string) $shop->id]);
    }

    /**
     * The shop's size in this unit, its own primary first, then a second size
     * that holds. Null when neither is in the unit.
     */
    private static function sizeIn(Shop $shop, string $unit, ?float $median): ?PackSize
    {
        $primary = self::statedSize($shop);

        if ($primary instanceof PackSize && $primary->unit === $unit) {
            return $primary;
        }

        $alt = self::usableAlt($shop);

        return $alt instanceof PackSize && $alt->unit === $unit && self::altHolds($shop, $alt, $median) ? $alt : null;
    }

    /** The second size, unless Jev rejected it or it repeats the primary's unit. */
    private static function usableAlt(Shop $shop): ?PackSize
    {
        $alt = $shop->altPackSize();

        if (! $alt instanceof PackSize || $shop->alt_pack_confirmed === false || $alt->unit === self::statedSize($shop)?->unit) {
            return null;
        }

        return $alt;
    }

    /** A second size holds when Jev confirmed it, or when it lands inside the field's band. */
    private static function altHolds(Shop $shop, PackSize $alt, ?float $median): bool
    {
        return $shop->alt_pack_confirmed === true || ! self::isImplausible($shop, $alt, $median);
    }

    /** The size the shops that state one agree on, which a silent page borrows. */
    public function sharedSize(): ?PackSize
    {
        return $this->agreed;
    }

    /** The unit this product compares in, or null when it has none. */
    public function unit(): ?string
    {
        return $this->unit;
    }

    public function hasComparisonUnit(): bool
    {
        return $this->unit !== null;
    }

    /**
     * This shop's answer. A product with no comparison unit answers null for
     * every shop: there is nothing to be excluded *from*, and stamping each row
     * with a reason would be noise rather than information.
     */
    public function for(Shop $shop): ?ComparablePack
    {
        return $this->packs[(string) $shop->id] ?? null;
    }

    /**
     * The shops that may win the per-unit ranking — a stated or a confirmed
     * size, in the comparison unit.
     *
     * @param  Collection<int, Shop>  $shops
     * @return Collection<int, Shop>
     */
    public function winnable(Collection $shops): Collection
    {
        // A usable unit price is part of being winnable, not a detail of the
        // sort. Without it a shop priced 0.00 sorts first — `unitPriceOf()`
        // answers null for a price at or below zero, and a null cast to float
        // is the smallest number there is.
        return $shops->filter(fn (Shop $shop): bool => $this->for($shop)?->canWin() === true
            && $this->unitPriceValueOf($shop) !== null);
    }

    /**
     * The cheapest of these shops per unit, with the oldest winning a tie.
     *
     * One definition of the winner, used by the ranking, the product page and
     * the public share page alike — a second one would let two surfaces crown
     * different shops.
     *
     * Ranked on the unrounded figure. Two decimals is coarser than the field
     * it sorts: a 400-tablet pack and an 800-tablet pack of the same tablet
     * both read `0.03` while being 18% apart, and the tie then went to whoever
     * was added first.
     *
     * @param  Collection<int, Shop>  $shops
     */
    public function cheapestPerUnit(Collection $shops): ?Shop
    {
        return $this->rankedPerUnit($shops)->first();
    }

    /**
     * The shops that may win, cheapest per unit first, in the order
     * {@see cheapestPerUnit()} crowns them: a list that leads with any other
     * shop disagrees with the headline on a tie.
     *
     * @param  Collection<int, Shop>  $shops
     * @return Collection<int, Shop>
     */
    public function rankedPerUnit(Collection $shops): Collection
    {
        return $this->winnable($shops)
            ->sort(fn (Shop $a, Shop $b): int => [$this->unitPriceValueOf($a), $a->created_at, (string) $a->id]
                <=> [$this->unitPriceValueOf($b), $b->created_at, (string) $b->id])
            ->values();
    }

    /**
     * The order the product page, its Markdown copy and the public page list
     * shops in, led by the shop the headline names:
     *
     *  1. the shops that may win per unit, in the ranking's own order;
     *  2. the other eligible shops by pack price, as the pack-price headline
     *     picks among them;
     *  3. every other row by price per unit, then pack price, unpriced last.
     *
     * A cheaper row that cannot be bought from — sold out, a size it did not
     * state, a trade-only price — never sits above the headline's shop.
     * Returns the `$shops` instances; `$eligible` is only read for which rows
     * qualify.
     *
     * @param  Collection<int, Shop>  $shops  the rows to order
     * @param  Collection<int, Shop>  $eligible  the shops allowed to win
     * @return Collection<int, Shop>
     */
    public function tableOrder(Collection $shops, Collection $eligible): Collection
    {
        $eligibleKeys = $eligible->map(fn (Shop $shop): string => $shop->id)->all();
        $isEligible = fn (Shop $shop): bool => in_array($shop->id, $eligibleKeys, strict: true);

        $ranked = $this->rankedPerUnit($shops->filter($isEligible));
        $rankedKeys = $ranked->map(fn (Shop $shop): string => $shop->id)->all();
        $unranked = $shops->reject(fn (Shop $shop): bool => in_array($shop->id, $rankedKeys, strict: true));

        $otherEligible = $unranked->filter($isEligible)
            ->sort(fn (Shop $a, Shop $b): int => [(float) $a->current_price, $a->created_at, (string) $a->id]
                <=> [(float) $b->current_price, $b->created_at, (string) $b->id]);

        $rest = $unranked->reject($isEligible)
            ->sortBy(fn (Shop $shop): array => [
                $this->unitPriceValueOf($shop) === null ? 1 : 0,
                $this->unitPriceValueOf($shop) ?? ($shop->current_price === null ? PHP_FLOAT_MAX : (float) $shop->current_price),
            ]);

        return $ranked->concat($otherEligible)->concat($rest)->values();
    }

    /** The figure a surface prints. {@see unitPriceValueOf()} is what ranks. */
    public function unitPriceOf(Shop $shop): ?string
    {
        return $this->for($shop)?->unitPriceFor($shop->current_price);
    }

    public function unitPriceValueOf(Shop $shop): ?float
    {
        return $this->for($shop)?->unitPriceValueFor($shop->current_price);
    }

    private static function resolveOne(
        Shop $shop,
        string $currency,
        string $unit,
        ?PackSize $agreed,
        ?float $median,
        ItemSizes $itemSizes,
    ): ComparablePack {
        if ($shop->currency !== $currency) {
            return ComparablePack::excluded(PackExclusion::DifferentCurrency, $shop->currency);
        }

        $size = self::statedSize($shop);

        // A shop that states a size nobody can read is not a shop that stated
        // nothing. Inheriting its siblings' size would paper over a bad row
        // with a plausible number; "pack size unknown" points at the fix.
        if ($size === null && $shop->pack_quantity !== null && $shop->pack_unit !== null) {
            return ComparablePack::excluded(PackExclusion::SizeUnknown);
        }

        if ($size instanceof PackSize) {
            if ($size->unit === $unit) {
                return ComparablePack::stated($size);
            }

            // The same pack, stated by the shop itself in this unit.
            $alt = self::usableAlt($shop);

            if ($alt instanceof PackSize && $alt->unit === $unit) {
                return self::altHolds($shop, $alt, $median)
                    ? ComparablePack::stated($alt)
                    : ComparablePack::excluded(PackExclusion::SizeImplausible);
            }

            // A count beside weights, or a weight beside counts: the item size
            // the shops that state both agree on links the two. "12 bars" at one
            // shop and "12 x 45 g" at the others is the same 540 g pack. Only an
            // estimate, like a borrowed size: it is shown, never crowned.
            $converted = $itemSizes->convert($size, $unit);

            return $converted instanceof PackSize
                ? self::inferredUnlessImplausible($shop, $converted, $median)
                : ComparablePack::excluded(PackExclusion::outsideTheUnit($size->unit));
        }

        $confirmed = $shop->confirmedPackSize();

        // Only while the other shops still agree on that size: once they move
        // on, or disagree, the borrowed size is in doubt again.
        if ($confirmed instanceof PackSize && $agreed instanceof PackSize && $confirmed->isSameSizeAs($agreed)) {
            return ComparablePack::confirmed($confirmed);
        }

        if (! $agreed instanceof PackSize) {
            return ComparablePack::excluded(PackExclusion::SizeUnknown);
        }

        return self::inferredUnlessImplausible($shop, $agreed, $median);
    }

    /** A size the shop did not state itself: an estimate, unless it lands far from the field. */
    private static function inferredUnlessImplausible(Shop $shop, PackSize $size, ?float $median): ComparablePack
    {
        return self::isImplausible($shop, $size, $median)
            ? ComparablePack::excluded(PackExclusion::SizeImplausible)
            : ComparablePack::inferred($size);
    }

    /**
     * A size that puts this shop far from every shop that leads with its own
     * is evidence the size is wrong, whichever way it lands.
     *
     * For an inherited size that is a guess borrowed from the siblings not
     * holding; the row could not win on it either way. A shop's second size
     * is its own word but a less tended field, and it can win once it holds,
     * so the guard matters more there, not less: such a size stays out until
     * Jev confirms it ({@see ConfirmAltPackSize}).
     */
    private static function isImplausible(Shop $shop, PackSize $size, ?float $median): bool
    {
        if ($median === null || $median <= 0.0) {
            return false;
        }

        $implied = $size->unitPriceValueFor((string) ($shop->current_price ?? ''));

        return $implied !== null
            && ($implied < $median * self::IMPLAUSIBLE_RATIO || $implied > $median / self::IMPLAUSIBLE_RATIO);
    }

    private static function statedSize(Shop $shop): ?PackSize
    {
        if ($shop->pack_quantity === null || ! is_string($shop->pack_unit)) {
            return null;
        }

        return PackSize::of((float) $shop->pack_quantity, $shop->pack_unit);
    }

    /**
     * The one size every shop compared in the unit agrees on, or null when
     * they disagree.
     * Disagreement is the signal that these shops genuinely sell different
     * packs, which per-unit comparison already handles without inventing
     * anything.
     *
     * @param  Collection<int, PackSize>  $inUnit
     */
    private static function agreedSize(Collection $inUnit): ?PackSize
    {
        $first = $inUnit->first();

        if (! $first instanceof PackSize) {
            return null;
        }

        return $inUnit->every(fn (PackSize $size): bool => $size->isSameSizeAs($first)) ? $first : null;
    }

    /**
     * Median unit price across the given sizes, which are the shops leading
     * with a unit, or their second sizes when none does — the field an
     * inherited or second size is tested against.
     *
     * @param  Collection<int, Shop>  $shops
     * @param  Collection<int, PackSize>  $inUnit  keyed by the same shop keys
     */
    private static function medianUnitPrice(Collection $shops, Collection $inUnit): ?float
    {
        $prices = $shops
            ->filter(fn (Shop $shop, int|string $key): bool => $inUnit->has($key) && $shop->current_price !== null)
            ->map(fn (Shop $shop, int|string $key): ?float => $inUnit->get($key)?->unitPriceValueFor((string) $shop->current_price))
            ->filter()
            ->sort()
            ->values();

        if ($prices->isEmpty()) {
            return null;
        }

        $middle = intdiv($prices->count(), 2);

        return $prices->count() % 2 === 1
            ? (float) $prices[$middle]
            : ((float) $prices[$middle - 1] + (float) $prices[$middle]) / 2;
    }
}
