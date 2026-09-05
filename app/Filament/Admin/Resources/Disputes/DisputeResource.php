<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Disputes;

use App\Billing\BillingGate;
use App\Filament\Admin\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Admin\Resources\Disputes\Tables\DisputesTable;
use App\Models\StripeDispute;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The chargeback queue. Every dispute Stripe reported lands here, so an
 * open one is never missed between mail alerts.
 */
class DisputeResource extends Resource
{
    protected static ?string $model = StripeDispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Chargebacks';

    protected static ?string $modelLabel = 'chargeback';

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
        return DisputesTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $open = StripeDispute::query()->whereNull('closed_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDisputes::route('/'),
        ];
    }
}
