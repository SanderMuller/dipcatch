<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Shop;
use Closure;
use Illuminate\Support\Collection;

/**
 * How much one piece weighs or holds, read from the shops that state their
 * pack both as a count and as a weight or volume: AH's 20 pieces and 560 g
 * make a 28 g fish finger.
 *
 * It is what moves a per-unit target, or a latch, from one unit into another,
 * so it answers only when the shops agree: the median of their item sizes,
 * with every one of them within 5% of it.
 */
final readonly class ItemSizes
{
    /** How far an item size may sit from the factor, either way, and still convert: 5%. */
    private const float BAND = 0.05;

    /** How far a weight may land from a whole number of items, in items, and still count them. */
    private const float WHOLE_ITEM_SLACK = 0.25;

    private const string COUNTED = 'counted';

    private const string DOUBT = 'doubt';

    private const string REJECTED = 'rejected';

    /**
     * @param  array<string, array{pieces: float, measure: float, unit: string, status: string, confirmed: bool}>  $pairs  keyed by shop id
     */
    private function __construct(private array $pairs) {}

    /**
     * Each voter stating a count and a weight or volume, with what is known
     * of its second size: counted when it holds, in doubt when it failed the
     * plausibility guard and Jev has not answered, rejected when Jev said no.
     *
     * @param  Collection<int, Shop>  $voters
     * @param  Closure(Shop, PackSize): bool  $holds  whether a second size passes the plausibility guard in its own unit
     */
    public static function of(Collection $voters, Closure $holds): self
    {
        $pairs = [];

        foreach ($voters as $shop) {
            $pair = self::pairOf($shop, $holds);

            if ($pair !== null) {
                $pairs[(string) $shop->id] = $pair;
            }
        }

        return new self($pairs);
    }

    /**
     * The item size between a piece and a weight or volume, or null — no
     * conversion — when no shop states both, the units are not a piece and a
     * weight or volume, or any pair is in doubt: a second size that failed the
     * guard without an answer, or an item size outside the band. A pair Jev
     * rejected is left out; one Jev confirmed outside the band blocks for
     * good, because then the shops really sell different items.
     */
    public function between(string $from, string $to): ?float
    {
        $result = $this->compute($from, $to);

        return $result === null || $result['outliers'] !== [] || $result['inDoubt'] ? null : $result['factor'];
    }

    /**
     * `$size` in `$unit`, through the item size the shops agree on: 12 pieces
     * of a 45 g bar are 540 g, and 560 g of 28 g fish fingers are 20 pieces.
     *
     * Stricter than {@see between()}, because the result sizes a visible row:
     * two shops must state both sizes, so one wrong pair cannot size every
     * other row, and a weight must come within a quarter item of a whole number.
     * 300 g of 28 g fingers is 10.7, which says another item or pack.
     */
    public function convert(PackSize $size, string $unit): ?PackSize
    {
        $factor = $this->between($size->unit, $unit);

        if ($factor === null || $this->pairsIn($size->unit === 'piece' ? $unit : $size->unit) < 2) {
            return null;
        }

        if ($size->unit === 'piece') {
            return PackSize::of($size->quantity * $factor, $unit);
        }

        $pieces = $size->quantity / $factor;

        return abs($pieces - round($pieces)) > self::WHOLE_ITEM_SLACK ? null : PackSize::of(round($pieces), $unit);
    }

    /** How many shops state both a count and a size in `$measure` that holds. */
    private function pairsIn(string $measure): int
    {
        return count(array_filter($this->pairs, fn (array $pair): bool => $pair['unit'] === $measure && $pair['status'] === self::COUNTED));
    }

    /**
     * The shops outside the band that Jev has not answered about: the
     * questions that would let a blocked conversion through.
     *
     * @return list<string> shop ids
     */
    public function outliers(string $from, string $to): array
    {
        $result = $this->compute($from, $to);

        return $result === null ? [] : array_values(array_filter(
            $result['outliers'],
            fn (string $id): bool => ($this->pairs[$id]['confirmed'] ?? false) === false,
        ));
    }

    /**
     * @return array{factor: float, outliers: list<string>, inDoubt: bool}|null
     */
    private function compute(string $from, string $to): ?array
    {
        $measure = $from === 'piece' ? $to : $from;

        if (($from !== 'piece' && $to !== 'piece') || ! in_array($measure, ['g', 'ml'], strict: true)) {
            return null;
        }

        $pairs = array_filter($this->pairs, fn (array $pair): bool => $pair['unit'] === $measure && $pair['status'] !== self::REJECTED);

        if ($pairs === []) {
            return null;
        }

        $sizes = array_map(fn (array $pair): float => $pair['measure'] / $pair['pieces'], $pairs);
        $sorted = array_values($sizes);
        sort($sorted);
        // The lower middle value on an even count, so the factor is always
        // one a shop actually states.
        $factor = $sorted[intdiv(count($sorted) - 1, 2)];

        $outliers = array_keys(array_filter(
            $sizes,
            // A hair of slack, so a size exactly 5% off is not lost to float error.
            fn (float $size): bool => abs($size - $factor) / $factor > self::BAND + 1e-9,
        ));

        return [
            'factor' => $factor,
            'outliers' => array_map(strval(...), $outliers),
            'inDoubt' => array_any($pairs, fn (array $pair): bool => $pair['status'] === self::DOUBT),
        ];
    }

    /**
     * @param  Closure(Shop, PackSize): bool  $holds
     * @return array{pieces: float, measure: float, unit: string, status: string, confirmed: bool}|null
     */
    private static function pairOf(Shop $shop, Closure $holds): ?array
    {
        $primary = $shop->packSize();
        $alt = $shop->altPackSize();

        if (! $primary instanceof PackSize || ! $alt instanceof PackSize || $primary->unit === $alt->unit) {
            return null;
        }

        [$pieces, $measure] = $primary->unit === 'piece' ? [$primary, $alt] : [$alt, $primary];

        if ($pieces->unit !== 'piece') {
            return null;
        }

        $status = match (true) {
            $shop->alt_pack_confirmed === false => self::REJECTED,
            $holds($shop, $alt) => self::COUNTED,
            default => self::DOUBT,
        };

        return [
            'pieces' => $pieces->quantity,
            'measure' => $measure->quantity,
            'unit' => $measure->unit,
            'status' => $status,
            'confirmed' => $shop->alt_pack_confirmed === true,
        ];
    }
}
