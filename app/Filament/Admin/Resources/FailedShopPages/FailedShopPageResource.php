<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\FailedShopPages;

use App\Filament\Admin\Resources\FailedShopPages\Pages\ListFailedShopPages;
use App\Filament\Admin\Resources\FailedShopPages\Tables\FailedShopPagesTable;
use App\Models\FailedShopPage;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Shop pages people tried to add and DipCatch could not read: the list to
 * work through when making more shops work. Read-only; a page someone later
 * reads without their own selectors drops off by itself.
 */
final class FailedShopPageResource extends Resource
{
    protected static ?string $model = FailedShopPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationCircle;

    protected static ?string $navigationLabel = 'Pages we could not read';

    protected static ?string $modelLabel = 'page we could not read';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return FailedShopPagesTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListFailedShopPages::route('/'),
        ];
    }
}
