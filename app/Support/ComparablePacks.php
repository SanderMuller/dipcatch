<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PackExclusion;
use App\Models\Product;
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
 * There is no third, "derived" provenance. Expressing a counted pack in weight
 * would need an item size from that shop's own page, and the write path has
 * already spent that evidence — {@see PackSize::resolve()} runs
 * {@see StatedPackSize::wholePack()} against the title, so a page reading
 * `12 x 55 g` is stored as 660 g. A row still saying `12 piece` is one whose
 * page stated no item size, and it is excluded rather than converted. Dividing a
 * sibling's weight by that count and multiplying it back returns the sibling's
 * weight: the count contributes nothing, and the arithmetic hides an assumption
 * that a 6-pack listed beside 12-packs makes catastrophically wrong.
 */
final readonly class ComparablePacks
{
    /**
     * How far below the stating shops' median unit price an inferred size may
     * land before it reads as a wrong size rather than a good offer.
     *
     * One-sided on purpose. A row stamped too *expensive* loses nothing a reader
     * acts on, because an inferred size can neither win nor alert either way.
     */
    private const float IMPLAUSIBLE_BELOW_MEDIAN = 0.60;

    /**
     * @param  array<string, ComparablePack>  $packs  keyed by shop id
     */
    private function __construct(
        private ?string $unit,
        private array $packs,
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
     * majority among the voters that state a size, so one of them is `Stated`
     * and winnable — but only if that voter is also a shop this resolver
     * answers for.
     *
     * @param  Collection<int, Shop>  $shops
     * @param  Collection<int, Shop>|null  $voters  a subset of `$shops`; defaults to all of them
     */
    public static function of(Collection $shops, string $currency, ?Collection $voters = null): self
    {
        $voters ??= $shops;

        $stated = $voters
            ->filter(fn (Shop $shop): bool => $shop->currency === $currency)
            ->map(fn (Shop $shop): ?PackSize => self::statedSize($shop))
            ->filter();

        $unit = self::majorityUnit($stated);

        if ($unit === null) {
            return new self(unit: null, packs: []);
        }

        $inUnit = $stated->filter(fn (PackSize $size): bool => $size->unit === $unit);
        $agreed = self::agreedSize($inUnit);
        $median = self::medianUnitPrice($voters, $inUnit);

        $packs = [];

        foreach ($shops as $shop) {
            $packs[(string) $shop->id] = self::resolveOne($shop, $currency, $unit, $agreed, $median);
        }

        return new self($unit, $packs);
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
     * The shops that may win the per-unit ranking — a stated size, in the
     * comparison unit.
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
        return $this->winnable($shops)
            ->sort(fn (Shop $a, Shop $b): int => [$this->unitPriceValueOf($a), $a->created_at, (string) $a->id]
                <=> [$this->unitPriceValueOf($b), $b->created_at, (string) $b->id])
            ->first();
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

            return ComparablePack::excluded($size->unit === 'piece'
                ? PackExclusion::SoldByThePiece
                : PackExclusion::UnitDoesNotConvert);
        }

        if (! $agreed instanceof PackSize) {
            return ComparablePack::excluded(PackExclusion::SizeUnknown);
        }

        return self::isImplausible($shop, $agreed, $median)
            ? ComparablePack::excluded(PackExclusion::SizeImplausible)
            : ComparablePack::inferred($agreed);
    }

    /**
     * An inherited size that makes this shop look far cheaper per unit than
     * every shop that stated its own is evidence the size is wrong.
     */
    private static function isImplausible(Shop $shop, PackSize $agreed, ?float $median): bool
    {
        if ($median === null) {
            return false;
        }

        $implied = $agreed->unitPriceValueFor((string) ($shop->current_price ?? ''));

        return $implied !== null && $implied < $median * self::IMPLAUSIBLE_BELOW_MEDIAN;
    }

    private static function statedSize(Shop $shop): ?PackSize
    {
        if ($shop->pack_quantity === null || ! is_string($shop->pack_unit)) {
            return null;
        }

        return PackSize::of((float) $shop->pack_quantity, $shop->pack_unit);
    }

    /**
     * The unit most of the product's stating shops use. An even split falls back
     * to the alphabetically first, so one product always answers the same way —
     * the same determinism rule {@see Product::unitPriceUnit()} uses.
     *
     * @param  Collection<int, PackSize>  $stated
     */
    private static function majorityUnit(Collection $stated): ?string
    {
        $counts = [];

        foreach ($stated as $size) {
            $counts[$size->unit] = ($counts[$size->unit] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        ksort($counts);
        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * The one size every stating shop agrees on, or null when they disagree.
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
     * Median unit price across the shops that stated a size in the comparison
     * unit — the field an inherited size is tested against.
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
