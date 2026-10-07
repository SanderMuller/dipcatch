<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Shop;
use Closure;
use Illuminate\Support\Collection;

/**
 * Which unit a product compares in: the one that brings the most shops into
 * the comparison, counting a shop's second size only where it holds.
 */
final readonly class UnitVote
{
    /**
     * @param  array<string, int>  $accepted  unit => shops it brings in
     * @param  array<string, ?float>  $medians  unit => the field's price per unit
     * @param  array<string, true>  $altsInDoubt  shop id => its second size failed the guard
     */
    private function __construct(
        public array $accepted,
        public array $medians,
        public array $altsInDoubt,
    ) {}

    /**
     * Every unit is tested, not only the winner: a second size that fails in
     * a unit the product does not pick is still in doubt, and still worth
     * asking Jev about.
     *
     * The median comes from the shops leading with the unit, so a second size
     * never vouches for itself — unless no shop leads with it at all.
     *
     * @param  Collection<int, Shop>  $voters
     * @param  Collection<int, PackSize>  $primaries  keyed as `$voters`
     * @param  Collection<int, PackSize>  $alts  keyed as `$voters`
     * @param  Closure(Collection<int, PackSize>): ?float  $median
     * @param  Closure(Shop, PackSize, ?float): bool  $holds
     */
    public static function tally(Collection $voters, Collection $primaries, Collection $alts, Closure $median, Closure $holds): self
    {
        $units = $primaries->merge($alts)->map(fn (PackSize $size): string => $size->unit)->unique()->sort()->values();
        $medians = [];
        $accepted = [];
        $altsInDoubt = [];

        foreach ($units as $unit) {
            $inUnit = $primaries->filter(fn (PackSize $size): bool => $size->unit === $unit);
            $altsInUnit = $alts->filter(fn (PackSize $size, int $key): bool => $size->unit === $unit && ! $inUnit->has($key));
            $medians[$unit] = $median($inUnit->isNotEmpty() ? $inUnit : $altsInUnit);
            $accepted[$unit] = $inUnit->count();

            foreach ($altsInUnit as $key => $alt) {
                $shop = $voters->get($key);

                if (! $shop instanceof Shop) {
                    continue;
                }

                if ($holds($shop, $alt, $medians[$unit])) {
                    $accepted[$unit]++;
                } else {
                    $altsInDoubt[(string) $shop->id] = true;
                }
            }
        }

        return new self($accepted, $medians, $altsInDoubt);
    }

    /**
     * The unit with the most shops. On a tie, the unit the product compares
     * in now — so a shop swapping which of its two sizes it leads with cannot
     * flip a product whose coverage did not change — then the unit most shops
     * lead with, then the alphabetically first, so one product always answers
     * the same way.
     *
     * @param  Collection<int, PackSize>  $primaries  each voter's own pack size
     */
    public function winner(Collection $primaries, ?string $currentUnit): ?string
    {
        if ($this->accepted === []) {
            return null;
        }

        $best = max($this->accepted);
        $tied = array_keys(array_filter($this->accepted, fn (int $count): bool => $count === $best));

        if (count($tied) === 1) {
            return $tied[0];
        }

        if ($currentUnit !== null && in_array($currentUnit, $tied, strict: true)) {
            return $currentUnit;
        }

        $leading = [];

        foreach ($tied as $unit) {
            $leading[$unit] = $primaries->filter(fn (PackSize $size): bool => $size->unit === $unit)->count();
        }

        ksort($leading);
        arsort($leading);

        return (string) array_key_first($leading);
    }
}
