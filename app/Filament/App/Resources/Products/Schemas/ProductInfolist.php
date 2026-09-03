<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Schemas;

use App\Filament\App\Resources\Products\Widgets\PriceHistoryChart;
use App\Models\Product;
use App\Models\Shop;
use App\Support\Favicon;
use App\Support\MoneyFormatter;
use App\Support\PromotionLabel;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The product overview, read top to bottom: what it is, what it costs and
 * where, how that price has moved, then the shops themselves.
 *
 * The two prices lead because they are the answer the page exists to give,
 * and they are quoted the same way — amount, price per unit, shop, and how
 * long it lasts — so the difference between cheapest and best value can be
 * read without arithmetic.
 */
final class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->columnSpanFull()
                    ->schema([
                        self::identity(),
                        self::price(),
                    ]),

                // Directly under the prices: the same number over time,
                // which is what makes a price readable as high or low.
                Livewire::make(PriceHistoryChart::class, fn (Product $record): array => ['record' => $record])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * What the product is, in three lines: the picture, the name, and the
     * facts as badges rather than as stacked label-and-value pairs — those
     * made a short card tall and left it half empty beside the prices.
     */
    private static function identity(): Section
    {
        return Section::make()
            ->columnSpan(1)
            ->schema([
                ImageEntry::make('image_url')
                    ->hiddenLabel()
                    // A fixed box the whole pack fits inside: stretching it to
                    // the card's width cropped a tall bag top and bottom.
                    ->imageSize(180)
                    ->extraImgAttributes(['class' => 'rounded-lg object-contain']),

                TextEntry::make('title')
                    ->hiddenLabel()
                    ->size('lg')
                    ->weight('bold'),

                TextEntry::make('facts')
                    ->hiddenLabel()
                    ->badge()
                    ->state(fn (Product $r): array => [
                        trans_choice(':count shop|:count shops', $r->shops->count()),
                        $r->active ? 'Active' : 'Paused',
                    ])
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Paused' => 'warning',
                        default => 'gray',
                    }),
            ]);
    }

    private static function price(): Section
    {
        return Section::make('Price')
            ->columnSpan(2)
            ->columns(2)
            ->schema([
                TextEntry::make('cheapest_price')
                    ->label('Cheapest now')
                    ->size('lg')
                    ->weight('bold')
                    ->state(fn (Product $r): string => MoneyFormatter::format(
                        $r->cheapest_price === null ? null : (string) $r->cheapest_price,
                        $r->currency,
                    ))
                    ->helperText(fn (Product $r): ?HtmlString => self::priceNote($r->cheapestShop, $r)),

                TextEntry::make('best_value')
                    ->label('Best value')
                    ->size('lg')
                    ->weight('bold')
                    ->state(fn (Product $r): string => self::unitPrice($r->bestValueShop(), $r) ?? '—')
                    ->helperText(fn (Product $r): HtmlString => self::bestValueNote($r)),

                // Below the two answers, the settings that decide when an
                // alert fires — worth seeing, not worth the same weight.
                TextEntry::make('drop_threshold_pct')
                    ->label('Alerts below')
                    ->columnSpanFull()
                    ->state(fn (Product $r): string => self::thresholds($r)),
            ]);
    }

    /**
     * What sits under the cheapest price: its price per unit, the shop, and
     * how long it lasts.
     */
    private static function priceNote(?Shop $shop, Product $product): ?HtmlString
    {
        if ($shop === null) {
            return null;
        }

        return self::note([
            self::unitPrice($shop, $product),
            PromotionLabel::short($shop),
        ], $shop);
    }

    private static function bestValueNote(Product $product): HtmlString
    {
        $shop = $product->bestValueShop();

        if ($shop === null) {
            return new HtmlString('No shop states a pack size yet.');
        }

        return self::note([
            MoneyFormatter::format(
                $shop->current_price === null ? null : (string) $shop->current_price,
                $product->currency,
            ),
            PromotionLabel::short($shop),
        ], $shop);
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    private static function note(array $parts, Shop $shop): HtmlString
    {
        return new HtmlString(
            Favicon::html($shop->host) . ' ' . e(implode(' · ', array_filter($parts))),
        );
    }

    private static function unitPrice(?Shop $shop, Product $product): ?string
    {
        $unitPrice = $shop?->unitPrice();

        if ($unitPrice === null) {
            return null;
        }

        return MoneyFormatter::format($unitPrice, $product->currency) . ' ' . $shop?->unitPriceLabel();
    }

    private static function thresholds(Product $product): string
    {
        $parts = array_filter([
            $product->drop_threshold_pct === null ? null : $product->drop_threshold_pct . ' %',
            $product->drop_threshold_abs === null
                ? null
                : MoneyFormatter::format((string) $product->drop_threshold_abs, $product->currency),
        ]);

        return $parts === [] ? 'Any drop' : implode(' · ', $parts);
    }
}
