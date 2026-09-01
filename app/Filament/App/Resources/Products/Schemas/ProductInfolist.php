<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Schemas;

use App\Models\Product;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ProductInfolist
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
                            )),

                        TextEntry::make('cheapestShop.host')
                            ->label('Cheapest at')
                            ->placeholder('—')
                            ->url(fn (Product $r): ?string => $r->cheapestShop?->url)
                            ->openUrlInNewTab()
                            ->icon(fn (Product $r): ?Heroicon => $r->cheapestShop === null
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
}
