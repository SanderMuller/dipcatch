<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmptySearches;

use App\Filament\Admin\Resources\EmptySearches\Pages\ListEmptySearches;
use App\Filament\Admin\Resources\EmptySearches\Tables\EmptySearchesTable;
use App\Models\EmptySearch;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * What people searched for in the header and did not find: a list to read,
 * not to edit. Rows go six months after the last search.
 */
final class EmptySearchResource extends Resource
{
    protected static ?string $model = EmptySearch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $navigationLabel = 'Searches without results';

    protected static ?string $modelLabel = 'search without results';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return EmptySearchesTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEmptySearches::route('/'),
        ];
    }
}
