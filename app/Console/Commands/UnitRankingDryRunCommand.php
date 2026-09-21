<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PackProvenance;
use App\Models\Product;
use App\Models\Shop;
use App\Support\ComparablePacks;
use App\Support\PackSize;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('dipcatch:unit-ranking-dry-run {--changes-only : Only products where the two answers name different shops}')]
#[Description('Show what unit-aware ranking makes of every product: its comparison unit, each shop resolved size and provenance, and which shop wins each answer. Reads only — writes nothing, sends nothing.')]
final class UnitRankingDryRunCommand extends Command
{
    /**
     * A wrong size is not visible in the winner list, because a size only shows
     * up once it moves something. Print the assumptions too.
     */
    public function handle(): int
    {
        $products = Product::query()->with('shops')->orderBy('title')->get();
        $changesOnly = (bool) $this->option('changes-only');
        $suspects = [];
        $stranded = [];

        foreach ($products as $product) {
            $packs = $product->comparablePacks();
            $lowest = $product->cheapestShop;
            $bestValue = $product->bestValueShop();
            $differs = $bestValue !== null && $lowest !== null && $bestValue->id !== $lowest->id;

            $suspects = [...$suspects, ...self::suspectRows($product, $packs)];

            // A product that compares per unit and has nobody to crown has
            // stopped alerting, and nothing else on this page would say so.
            if ($packs->hasComparisonUnit() && $bestValue === null && $lowest !== null) {
                $stranded[] = $product->title . ' — compares in ' . $packs->unit() . ', no shop can win';
            }

            if ($changesOnly && ! $differs) {
                continue;
            }

            $this->line('');
            $this->info($product->title . '  [' . ($packs->unit() ?? 'no comparison unit') . ']');
            $this->line('  lowest price: ' . ($lowest === null ? '—' : $lowest->host)
                . '   best value: ' . ($bestValue === null ? '—' : $bestValue->host)
                . ($differs ? '   ← two different shops' : ''));

            foreach ($product->shops as $shop) {
                $pack = $packs->for($shop);
                $note = match (true) {
                    $pack === null => 'pack basis',
                    $pack->isExcluded() => 'excluded — ' . $pack->reason(),
                    default => sprintf(
                        '%s (%s)  %s',
                        self::amount($pack->size),
                        (string) $pack->provenance?->value,
                        $packs->unitPriceOf($shop) ?? '—',
                    ),
                };

                $this->line(sprintf('    %-28s %-10s %s', $shop->host, (string) $shop->current_price, $note));
            }
        }

        $this->rows('Products that compare per unit with no winner — these have stopped alerting', $stranded);
        $this->rows('Sizes nobody stated', $suspects);
        $this->rows('Rows whose URL carries no product identifier', self::urlsWithoutAProductPath());
        $this->rows('The same URL tracked on more than one product', self::urlsOnSeveralProducts());
        $this->rows('Variant-selector pages tracked without a variant_key', self::variantPagesWithoutAKey());

        return self::SUCCESS;
    }

    /**
     * Every inferred size, with what it implies. The winner list shows what
     * moved; this shows what could move later on an assumption nobody checked.
     *
     * @return list<string>
     */
    private static function suspectRows(Product $product, ComparablePacks $packs): array
    {
        $rows = [];

        foreach ($product->shops as $shop) {
            $pack = $packs->for($shop);

            if ($pack === null || $pack->provenance !== PackProvenance::Inferred) {
                continue;
            }

            $rows[] = sprintf(
                '%s · %s inherited %s → %s',
                $product->title,
                $shop->host,
                self::amount($pack->size),
                $packs->unitPriceOf($shop) ?? '—',
            );
        }

        return $rows;
    }

    private static function amount(?PackSize $size): string
    {
        if ($size === null) {
            return 'unknown';
        }

        return rtrim(rtrim(number_format($size->quantity, 2, '.', ''), '0'), '.') . ' ' . $size->unit;
    }

    /**
     * A category listing carrying a price and a size from list-page JSON-LD.
     * `bodyandfit.com/en/products/protein-bars` was tracked on three Barebells
     * flavours at once, all showing the same money. Unit ranking makes a row
     * like that eligible to win.
     *
     * @return list<string>
     */
    private static function urlsWithoutAProductPath(): array
    {
        return Shop::query()
            ->where('active', true)
            ->get()
            ->filter(static function (Shop $shop): bool {
                $path = parse_url($shop->url, PHP_URL_PATH);

                if (! is_string($path)) {
                    return true;
                }

                // Any segment carrying a number is an identifier, not only the
                // last one. Reading the basename alone flagged every
                // `/producten/product/wi156794/fanta-cassis` on the account —
                // seven rows of noise around the one listing this is for.
                return ! array_any(
                    explode('/', $path),
                    static fn (string $segment): bool => preg_match('/\d/', $segment) === 1,
                );
            })
            ->map(static fn (Shop $shop): string => $shop->url)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function urlsOnSeveralProducts(): array
    {
        return Shop::query()
            ->where('active', true)
            ->get()
            ->groupBy('url_hash')
            ->filter(static fn (Collection $group): bool => $group->pluck('product_id')->unique()->count() > 1)
            ->map(static fn (Collection $group): string => $group->first()?->url . ' — ' . $group->count() . ' products')
            ->values()
            ->all();
    }

    /**
     * `realsupps.nl/products/barebells-repen-12-x-55g` is tracked on two
     * flavours, and both read the page default. The prices are equal today, so
     * nothing looks wrong — a single-flavour promotion would be attributed to
     * the wrong bar.
     *
     * @return list<string>
     */
    private static function variantPagesWithoutAKey(): array
    {
        return Shop::query()
            ->where('active', true)
            ->whereNull('variant_key')
            ->get()
            ->groupBy('url_hash')
            ->filter(static fn (Collection $group): bool => $group->count() > 1)
            ->map(static fn (Collection $group): string => (string) $group->first()?->url)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $rows
     */
    private function rows(string $heading, array $rows): void
    {
        $this->line('');
        $this->info($heading . ' (' . count($rows) . ')');

        foreach ($rows as $row) {
            $this->line('  ' . $row);
        }
    }
}
