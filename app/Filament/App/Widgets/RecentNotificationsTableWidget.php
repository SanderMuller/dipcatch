<?php declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Products\ProductResource;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Notifications\DatabaseNotification;

class RecentNotificationsTableWidget extends BaseWidget
{
    /** Notifiable type column value for our user model. */
    private const string NOTIFIABLE_TYPE = User::class;

    /** Notification class we filter on. */
    private const string NOTIFICATION_TYPE = PriceDropNotification::class;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent price-drop alerts')
            ->emptyStateHeading('No alerts yet')
            ->emptyStateDescription('Once a tracked product drops below your threshold, the alert appears here.')
            ->query($this->scopedQuery())
            ->paginated(false)
            ->columns([
                TextColumn::make('title')
                    ->state(fn (DatabaseNotification $n): string => self::field($n, 'title') ?? '—')
                    ->wrap()
                    ->url(fn (DatabaseNotification $n): ?string => $this->productUrl($n))
                    ->openUrlInNewTab(false),
                TextColumn::make('drop_percent')
                    ->visibleFrom('md')
                    ->label('Drop %')
                    ->state(fn (DatabaseNotification $n): string => self::formatPercent(self::field($n, 'drop_percent'))),
                TextColumn::make('drop_absolute')
                    ->label('Drop (abs)')
                    ->state(fn (DatabaseNotification $n): string => self::dropAmount($n)),
                TextColumn::make('created_at')
                    ->visibleFrom('md')
                    ->label('Sent')
                    ->since(),
            ]);
    }

    /**
     * @return EloquentQueryBuilder<DatabaseNotification>
     */
    private function scopedQuery(): EloquentQueryBuilder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', self::NOTIFIABLE_TYPE)
            ->where('notifiable_id', auth()->id())
            ->where('type', self::NOTIFICATION_TYPE)->latest()
            ->limit(10);
    }

    private function productUrl(DatabaseNotification $notification): ?string
    {
        $productId = self::field($notification, 'product_id');
        if (! is_string($productId) || $productId === '') {
            return null;
        }

        return ProductResource::getUrl('view', ['record' => $productId]);
    }

    private static function field(DatabaseNotification $notification, string $key): ?string
    {
        /** @var array<string, mixed> $data */
        $data = (array) $notification->data;
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * The column shows the size of the drop, so the stored (negative) delta is
     * rendered as its absolute value.
     */
    private static function dropAmount(DatabaseNotification $notification): string
    {
        $amount = self::field($notification, 'drop_absolute');
        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return '—';
        }

        return MoneyFormatter::format(
            (string) abs((float) $amount),
            self::field($notification, 'currency') ?? '',
        );
    }

    private static function formatPercent(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }

        return sprintf('-%0.1f%%', abs((float) $raw));
    }
}
