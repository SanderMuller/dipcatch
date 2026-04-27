<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\WaitlistSignups;

use App\Filament\Admin\Resources\WaitlistSignups\Pages\ListWaitlistSignups;
use App\Filament\Admin\Resources\WaitlistSignups\Tables\WaitlistSignupsTable;
use App\Models\WaitlistSignup;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WaitlistSignupResource extends Resource
{
    protected static ?string $model = WaitlistSignup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'email';

    public static function table(Table $table): Table
    {
        return WaitlistSignupsTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListWaitlistSignups::route('/'),
        ];
    }
}
