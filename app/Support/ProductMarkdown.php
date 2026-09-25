<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PackProvenance;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Collection;

/**
 * A product page as markdown: the owner's page at `/app/products/{product}.md`,
 * and the public share page at `/p/{slug}.md`.
 *
 * Built as a string rather than a Blade view: Blade escapes for HTML, so a
 * title with `&` would arrive as `&amp;`, and a raw echo would let a scraped
 * title carry a `|` that breaks the shops table. Every figure comes from the
 * same model methods and formatters the page uses, so the two cannot disagree.
 */
final readonly class ProductMarkdown
{
    public static function of(Product $product): string
    {
        $packs = $product->comparablePacks();
        $headline = HeadlinePrice::of($product, $packs);
        $shops = $packs->tableOrder($product->shops()->orderBy('current_price')->get(), $product->eligibleShops());

        $lines = ['# ' . self::inline($product->title), ''];
        $lines[] = '- ' . __('Status') . ': ' . ($product->active ? __('Active') : __('Paused'));

        if ($product->category !== null) {
            $lines[] = '- ' . __('Category') . ': ' . $product->category->label();
        }

        $lines[] = '- ' . __('Page') . ': ' . route('app.products.show', $product);

        $lines = [...$lines, ...self::headline($headline)];
        $lines = [...$lines, '', '## ' . __('Alerts'), ''];
        $rules = AlertRules::of($product);

        if ($rules === []) {
            $lines[] = '- ' . __('Any drop');
        }

        foreach ($rules as $rule) {
            $lines[] = '- ' . ($rule['below'] ? __(':value or less', ['value' => $rule['value']]) : $rule['value'])
                . (($rule['pro'] ?? false) ? ' (' . __('Pro') . ')' : '');
        }

        $lines = [...$lines, '', '## ' . __('Tracked shops'), ''];

        if ($shops->isEmpty()) {
            $lines[] = __('No shops yet. Add one to start following a price.');
        } elseif ($packs->hasComparisonUnit()) {
            $lines = [...$lines, ...self::table(
                [__('Shop'), __('Price :unit', ['unit' => UnitWord::forCode($packs->unit())]), __('Pack price'), __('In stock'), __('Price read')],
                $shops->map(fn (Shop $shop): array => [
                    self::link($shop),
                    self::unitPrice($shop, $packs),
                    $shop->isReference() ? '—' : PackLine::of($shop, $packs)->text(),
                    self::stock($shop),
                    self::freshness($shop),
                ])->all(),
            )];
        } else {
            $lines = [...$lines, ...self::table(
                [__('Shop'), __('Price'), __('Note'), __('In stock'), __('Price read')],
                $shops->map(fn (Shop $shop): array => [
                    self::link($shop),
                    self::price($shop),
                    self::unitPrice($shop, $packs),
                    self::stock($shop),
                    self::freshness($shop),
                ])->all(),
            )];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The public share page. Takes the shops the share controller already
     * loaded through its column allowlist, and says nothing the page does not
     * show a guest: no alert rules, no notes, no stock for a shop it hides.
     *
     * @param  Collection<int, Shop>  $shops  visible shops, cheapest first
     */
    public static function shared(Product $product, Collection $shops, HeadlinePrice $headline): string
    {
        $packs = $headline->packs;
        $lines = ['# ' . self::inline($product->title), ''];

        if ($product->publicShareUrl() !== null) {
            $lines = [...$lines, '- ' . __('Page') . ': ' . $product->publicShareUrl()];
        }

        $headlineShop = $headline->shop !== null && $shops->contains($headline->shop) ? $headline->shop : null;

        if ($headlineShop === null) {
            return implode("\n", [...$lines, '', '## ' . __('Best price now'), '', __('No live price available right now.')]) . "\n";
        }

        if ($headline->isPerUnit()) {
            $lines = [...$lines, ...self::headline($headline)];
        } else {
            $lines = [...$lines, '', '## ' . __('Best price now'), ''];
            $lines[] = self::sharedPrice($headlineShop) . ' ' . __('at') . ' ' . self::link($headlineShop);
        }

        $lines[] = '';
        $lines[] = trans_choice('Compared across :count shop tracked.|Compared across :count shops tracked.', $shops->count());

        $lines = [...$lines, '', '## ' . __('Shops'), ''];
        $lines = [...$lines, ...($packs->hasComparisonUnit()
            ? self::table(
                [__('Shop'), __('Price :unit', ['unit' => UnitWord::forCode($packs->unit())]), __('Pack price'), __('Price read')],
                $shops->map(fn (Shop $shop): array => [
                    self::link($shop),
                    self::unitPrice($shop, $packs),
                    PackLine::of($shop, $packs)->text(),
                    self::freshness($shop),
                ])->all(),
            )
            : self::table(
                [__('Shop'), __('Price'), __('Price read')],
                $shops->map(fn (Shop $shop): array => [
                    self::link($shop),
                    self::sharedPrice($shop),
                    self::freshness($shop),
                ])->all(),
            ))];

        return implode("\n", $lines) . "\n";
    }

    /**
     * The figure the page leads with, and the lowest-price note under it.
     *
     * @return list<string>
     */
    private static function headline(HeadlinePrice $headline): array
    {
        $shop = $headline->shop;

        if (! $headline->isPerUnit()) {
            return ['', '## ' . __('Best price now'), '', $shop === null
                ? $headline->text()
                : self::price($shop) . ' ' . __('at') . ' ' . self::link($shop)];
        }

        $lines = ['', '## ' . __('Best value'), ''];
        $lines[] = $headline->text() . ' ' . __('at') . ' ' . ($shop === null ? '—' : self::link($shop));
        $lines[] = '';
        $lines[] = (string) $headline->packLine()?->text();

        $lowest = $headline->lowestShop;

        if ($lowest !== null) {
            $percent = $headline->lowestCostsMorePercent();
            $note = __('Lowest price: :line at :host.', ['line' => PackLine::of($lowest, $headline->packs)->text(), 'host' => $lowest->host]);

            if ($percent !== null) {
                $note .= ' ' . __('That is :percent% more :unit than the best value.', [
                    'percent' => $percent,
                    'unit' => UnitWord::forCode($headline->unit) ?? __('per unit'),
                ]);
            }

            $lines = [...$lines, '', '> ' . $note];
        }

        return $lines;
    }

    /**
     * @param  list<string>  $header
     * @param  array<int, array<int, string>>  $rows
     * @return list<string>
     */
    private static function table(array $header, array $rows): array
    {
        $lines = [
            '| ' . implode(' | ', $header) . ' |',
            '|' . str_repeat(' --- |', count($header)),
        ];

        foreach ($rows as $row) {
            $lines[] = '| ' . implode(' | ', array_map(self::cell(...), $row)) . ' |';
        }

        return $lines;
    }

    /**
     * The price with the multi-buy terms the public page prints under it.
     */
    private static function sharedPrice(Shop $shop): string
    {
        $bundle = BundlePriceLabel::forShop($shop);

        return self::price($shop) . ($bundle === null ? '' : ' · ' . $bundle);
    }

    /**
     * The pack price with the regular price beside it during a multi-buy, as
     * `x-shop-price` shows it.
     */
    private static function price(Shop $shop): string
    {
        if ($shop->isReference()) {
            return __('Link only');
        }

        $price = MoneyFormatter::format(self::decimal($shop->current_price), $shop->currency);

        return $shop->liveBundleOffer() === null
            ? $price
            : $price . ' (' . __('Regular price') . ' ' . MoneyFormatter::format($shop->singleItemPrice(), $shop->currency) . ')';
    }

    /**
     * The per-unit column, or the one reason the shop takes no part in that
     * comparison — the same order of precedence as `x-shop-unit-price`.
     */
    private static function unitPrice(Shop $shop, ComparablePacks $packs): string
    {
        $pack = $packs->for($shop);
        $unitPrice = $packs->unitPriceOf($shop);

        return match (true) {
            $shop->isReference() => (string) $shop->kind->note(),
            $shop->notAConsumerPriceReason() !== null => (string) $shop->notAConsumerPriceReason(),
            ! $packs->hasComparisonUnit() => '—',
            $pack !== null && $pack->isExcluded() => (string) $pack->reason(),
            $unitPrice !== null => MoneyFormatter::unitPrice($unitPrice, $shop->currency) . ' ' . $pack?->size?->label()
                . ($pack?->provenance === PackProvenance::Inferred ? ' (' . __('estimated') . ')' : ''),
            default => '—',
        };
    }

    private static function stock(Shop $shop): string
    {
        return match ($shop->current_in_stock) {
            true => __('In stock'),
            false => __('Out of stock'),
            null => __('Stock unknown'),
        };
    }

    /**
     * When the price was last read, not when a read was last tried — see
     * `x-shop-freshness`.
     */
    private static function freshness(Shop $shop): string
    {
        $readAt = $shop->priceReadAt();

        return match (true) {
            $readAt === null => __('never read'),
            $shop->readsAreFailing() => __('read :ago, failing since', ['ago' => $readAt->diffForHumans()]),
            default => $readAt->diffForHumans(),
        };
    }

    /**
     * Angle brackets keep a URL with parentheses or spaces in one link.
     */
    private static function link(Shop $shop): string
    {
        return '[' . self::inline((string) $shop->host) . '](<' . str_replace(['<', '>'], ['%3C', '%3E'], $shop->url) . '>)';
    }

    /**
     * One table cell: a pipe would end the cell early, a newline the row.
     */
    private static function cell(string $text): string
    {
        return str_replace('|', '\|', self::inline($text));
    }

    private static function inline(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    private static function decimal(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
