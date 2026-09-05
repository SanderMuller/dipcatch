<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscribers;

use App\Billing\BillingGate;
use App\Filament\Admin\Resources\Subscribers\Pages\ListSubscribers;
use App\Filament\Admin\Resources\Subscribers\Tables\SubscribersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Every account with a billing relationship, read entirely from the local
 * mirror. Stripe is never called per row — a table that phoned Stripe for
 * each customer would hit the rate limit and stop rendering.
 */
class SubscriberResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $recordTitleAttribute = 'email';

    protected static ?string $navigationLabel = 'Subscribers';

    protected static ?string $modelLabel = 'subscriber';

    /**
     * Before Stripe is configured these screens can only ever be empty.
     * They come back the moment the shop opens; the routes stay reachable
     * for anyone who bookmarked them.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return BillingGate::isConfigured();
    }

    public static function table(Table $table): Table
    {
        return SubscribersTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSubscribers::route('/'),
        ];
    }
}
