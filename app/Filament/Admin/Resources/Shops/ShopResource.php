<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Shops;

use App\Filament\Admin\Resources\Shops\Pages\ListShops;
use App\Filament\Admin\Resources\Shops\Tables\ShopsTable;
use App\Models\Shop;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ShopResource extends Resource
{
    protected static ?string $model = Shop::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'host';

    public static function table(Table $table): Table
    {
        return ShopsTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListShops::route('/'),
        ];
    }
}
