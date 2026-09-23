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
        $cheapest = $product->cheapestShop;
        $bestValue = $product->bestValueShop();
        $shops = $product->shops()->orderBy('current_price')->get();

        $lines = ['# ' . self::inline($product->title), ''];
        $lines[] = '- ' . __('Status') . ': ' . ($product->active ? __('Active') : __('Paused'));

        if ($product->category !== null) {
            $lines[] = '- ' . __('Category') . ': ' . $product->category->label();
        }

        $lines[] = '- ' . __('Page') . ': ' . route('app.products.show', $product);

        $lines = [...$lines, '', '## ' . __('Best price now'), ''];
        $lines[] = $cheapest === null
            ? MoneyFormatter::format(self::decimal($product->cheapest_price), $product->currency)
            : self::price($cheapest) . ' ' . __('at') . ' ' . self::link($cheapest);

        // The lowest price can be a small pack that costs more per unit, which
        // the page warns about under the same heading.
        $cheapestUnit = $cheapest === null ? null : $packs->unitPriceValueOf($cheapest);
        $bestUnit = $bestValue === null || $bestValue->is($cheapest) ? null : $packs->unitPriceValueOf($bestValue);

        if ($bestValue !== null && $cheapestUnit !== null && $bestUnit !== null && $bestUnit > 0 && $cheapestUnit > $bestUnit) {
            $lines = [...$lines, '', '> ' . __(':percent% more :unit than the best value: :price at :host.', [
                'percent' => max(1, (int) round(($cheapestUnit - $bestUnit) / $bestUnit * 100)),
                'unit' => UnitWord::forCode($packs->unit()) ?? __('per unit'),
                'price' => MoneyFormatter::unitPrice($packs->unitPriceOf($bestValue), $bestValue->currency) . UnitWord::labelFor($packs->unit()),
                'host' => $bestValue->host,
            ])];
        }

        $lines = [...$lines, '', '## ' . __('Best value'), ''];
        $lines[] = $bestValue === null
            ? '—'
            : self::unitPrice($bestValue, $packs) . ' ' . __('at') . ' ' . self::link($bestValue);

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
        } else {
            $lines = [...$lines, ...self::table(
                [__('Shop'), __('Price'), __('Price per kilo or piece'), __('In stock'), __('Price read')],
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
    public static function shared(Product $product, Collection $shops, ComparablePacks $packs, ?string $bestValueShopId): string
    {
        $headline = $shops->first();
        $bestValue = $shops->count() > 1 ? $shops->firstWhere('id', $bestValueShopId) : null;

        $lines = ['# ' . self::inline($product->title), ''];

        if ($product->publicShareUrl() !== null) {
            $lines = [...$lines, '- ' . __('Page') . ': ' . $product->publicShareUrl(), ''];
        }

        $lines = [...$lines, '## ' . __('Best price now'), ''];

        if ($headline === null) {
            $lines[] = __('No live price available right now.');
        } else {
            $lines[] = self::sharedPrice($headline) . ' ' . __('at') . ' ' . self::link($headline);
            $lines[] = '';
            $lines[] = trans_choice('Cheapest across :count shop tracked.|Cheapest across :count shops tracked.', $shops->count());
        }

        if ($bestValue !== null) {
            $lines = [...$lines, '', '## ' . __('Best value'), ''];
            $lines[] = self::sharedUnitPrice($bestValue, $packs) . ' ' . __('at') . ' ' . self::link($bestValue);
        }

        if ($shops->isNotEmpty()) {
            $lines = [...$lines, '', '## ' . __('Shops'), ''];
            $lines = [...$lines, ...self::table(
                [__('Shop'), __('Price'), __('Price per kilo or piece'), __('Price read')],
                $shops->map(fn (Shop $shop): array => [
                    self::link($shop),
                    self::sharedPrice($shop),
                    self::sharedUnitPrice($shop, $packs),
                    self::freshness($shop),
                ])->all(),
            )];
        }

        return implode("\n", $lines) . "\n";
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
     * The per-unit figure the public page shows: the pack reason when the
     * shop is out of the comparison, else its own unit price.
     */
    private static function sharedUnitPrice(Shop $shop, ComparablePacks $packs): string
    {
        $pack = $packs->for($shop);

        if ($pack !== null && $pack->isExcluded()) {
            return (string) $pack->reason();
        }

        $unitPrice = $shop->unitPrice();

        return $unitPrice === null ? '—' : MoneyFormatter::unitPrice($unitPrice, $shop->currency) . $shop->packUnitLabel();
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
            ! $packs->hasComparisonUnit() => $shop->unitPrice() === null
                ? '—'
                : MoneyFormatter::unitPrice($shop->unitPrice(), $shop->currency) . $shop->packUnitLabel(),
            $pack !== null && $pack->isExcluded() => (string) $pack->reason(),
            $unitPrice !== null => MoneyFormatter::unitPrice($unitPrice, $shop->currency) . $pack?->size?->label()
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
