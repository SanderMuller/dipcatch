<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Every account, not only the ones with a billing relationship, and the one
 * place Pro is granted without payment.
 *
 * Unlike `SubscriberResource` this stays in the navigation with Stripe
 * unconfigured: an owner still has users to look at before they sell
 * anything.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'email';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $modelLabel = 'user';

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }
}
