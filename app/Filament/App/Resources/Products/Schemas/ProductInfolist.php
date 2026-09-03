<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\Shop;
use App\Support\Favicon;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

final class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        ImageEntry::make('image_url')
                            ->label('')
                            ->circular()
                            ->imageSize(120),

                        TextEntry::make('title')->size('lg'),
                    ]),

                Section::make('Pricing')
                    ->schema([
                        TextEntry::make('cheapest_price')
                            ->label('Cheapest now')
                            ->state(fn (Product $r): string => MoneyFormatter::format(
                                $r->cheapest_price === null ? null : (string) $r->cheapest_price,
                                $r->currency,
                            ))
                            // The same measure the best value is stated in, so
                            // the two lines can be read against each other.
                            ->helperText(fn (Product $r): ?string => self::unitPrice($r->cheapestShop, $r)),

                        TextEntry::make('cheapestShop.host')
                            ->label('Cheapest at')
                            ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(Favicon::html($state)))
                            ->placeholder('—')
                            ->url(fn (Product $r): ?string => $r->cheapestShop?->url)
                            ->openUrlInNewTab()
                            ->icon(fn (Product $r): ?Heroicon => $r->cheapestShop === null
                                ? null
                                : Heroicon::ArrowTopRightOnSquare)
                            ->iconPosition('after'),

                        TextEntry::make('best_value')
                            ->label('Best value')
                            ->state(fn (Product $r): string => self::unitPrice($r->bestValueShop(), $r) ?? '—')
                            ->helperText('Lowest price per unit, which is not always the lowest price.'),

                        TextEntry::make('best_value_shop')
                            ->label('Best value at')
                            ->state(fn (Product $r): ?string => $r->bestValueShop()?->host)
                            ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(Favicon::html($state)))
                            ->placeholder('—')
                            ->url(fn (Product $r): ?string => $r->bestValueShop()?->url)
                            ->openUrlInNewTab()
                            ->icon(fn (Product $r): ?Heroicon => $r->bestValueShop() === null
                                ? null
                                : Heroicon::ArrowTopRightOnSquare)
                            ->iconPosition('after'),

                        TextEntry::make('drop_threshold_pct')
                            ->label('Drop threshold (%)')
                            ->state(fn (Product $r): string => $r->drop_threshold_pct === null
                                ? '—'
                                : $r->drop_threshold_pct . ' %'),

                        TextEntry::make('drop_threshold_abs')
                            ->label('Drop threshold (absolute)')
                            ->state(fn (Product $r): string => MoneyFormatter::format(
                                $r->drop_threshold_abs === null ? null : (string) $r->drop_threshold_abs,
                                $r->currency,
                            )),

                        TextEntry::make('active')
                            ->label('Tracking')
                            ->badge()
                            ->state(fn (Product $r): string => $r->active ? 'Active' : 'Paused')
                            ->color(fn (Product $r): string => $r->active ? 'success' : 'gray'),
                    ])
                    ->columns(),
            ]);
    }

    /**
     * A shop's price per unit, with the unit it is measured in — "EUR 5.38
     * /kg". Null when the shop states no pack size, because a price per
     * nothing is not a number worth showing.
     */
    private static function unitPrice(?Shop $shop, Product $product): ?string
    {
        $unitPrice = $shop?->unitPrice();

        if ($unitPrice === null) {
            return null;
        }

        return MoneyFormatter::format($unitPrice, $product->currency) . ' ' . $shop?->unitPriceLabel();
    }
}
