<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                            ->imageSize(80),

                        TextEntry::make('title')->size('lg'),
                        TextEntry::make('url')
                            ->label('Source URL')
                            ->copyable()
                            ->url(fn (Product $record): string => $record->url, true),
                    ]),

                Section::make('Pricing')
                    ->schema([
                        TextEntry::make('initial_price')
                            ->state(fn (Product $r): string => $r->currency . ' ' . $r->initial_price)
                            ->label('Initial price'),
                        TextEntry::make('initial_checked_at')
                            ->dateTime()
                            ->label('Initial check'),
                        TextEntry::make('last_price')
                            ->state(fn (Product $r): ?string => $r->last_price === null ? null : $r->currency . ' ' . $r->last_price)
                            ->placeholder('—')
                            ->label('Last price'),
                        TextEntry::make('last_checked_at')
                            ->since()
                            ->placeholder('Never')
                            ->label('Last checked'),
                        TextEntry::make('last_status')
                            ->badge()
                            ->label('Status'),
                        TextEntry::make('drop_threshold_pct')
                            ->state(fn (Product $r): string => $r->drop_threshold_pct . ' %  /  ' . $r->currency . ' ' . $r->drop_threshold_abs)
                            ->label('Threshold'),
                        IconEntry::make('active')
                            ->boolean(),
                        IconEntry::make('needs_js')
                            ->boolean()
                            ->label('Needs JS')
                            ->trueIcon('heroicon-o-exclamation-triangle')
                            ->falseIcon('heroicon-o-check-circle')
                            ->trueColor('warning')
                            ->falseColor('success'),
                    ])
                    ->columns(2),

                Section::make('Selectors')
                    ->collapsible()
                    ->schema([
                        TextEntry::make('price_selector')->label('Primary'),
                        TextEntry::make('fallback_selectors')
                            ->state(fn (Product $r): string => implode("\n", (array) $r->fallback_selectors))
                            ->placeholder('—'),
                        TextEntry::make('image_selector')->placeholder('—'),
                        TextEntry::make('title_selector')->placeholder('—'),
                    ])
                    ->columns(2),

                Section::make('Recent checks')
                    ->description('Last 20 scrape attempts for this product. The full price-history chart will live here once dashboard.md ships.')
                    ->schema([
                        TextEntry::make('recent_checks_placeholder')
                            ->state(fn (Product $r): string => sprintf(
                                '%d total checks recorded; chart placeholder.',
                                $r->priceChecks()->count(),
                            ))
                            ->label(''),
                    ]),
            ]);
    }
}
