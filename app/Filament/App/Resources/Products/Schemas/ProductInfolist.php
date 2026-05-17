<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
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
                    ]),

                Section::make('Pricing')
                    ->schema([
                        TextEntry::make('cheapest_price')
                            ->state(fn (Product $r): ?string => $r->cheapest_price === null
                                ? null
                                : $r->currency . ' ' . $r->cheapest_price)
                            ->placeholder('—')
                            ->label('Cheapest now'),
                        TextEntry::make('cheapestShop.host')
                            ->placeholder('—')
                            ->label('Cheapest at'),
                        TextEntry::make('drop_threshold_pct')
                            ->state(fn (Product $r): string => ($r->drop_threshold_pct ?? '—') . ' %  /  ' . $r->currency . ' ' . ($r->drop_threshold_abs ?? '—'))
                            ->label('Threshold'),
                        IconEntry::make('active')
                            ->boolean(),
                    ])
                    ->columns(2),

                Section::make('Shops')
                    ->description('Paste a product URL from any webshop to track its price here.')
                    ->schema([
                        TextEntry::make('offers_count_placeholder')
                            ->state(fn (Product $r): string => $r->shops()->count() . ' shop(s) tracked')
                            ->label(''),
                        View::make('filament.partials.add-shop-livewire'),
                    ]),
            ]);
    }
}
