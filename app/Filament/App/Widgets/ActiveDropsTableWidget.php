<?php declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;

class ActiveDropsTableWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Active drops')
            ->emptyStateHeading('No active drops right now')
            ->emptyStateDescription('DipCatch is watching. We\'ll alert you when a price drops below your threshold.')
            ->query($this->scopedQuery())
            ->paginated(false)
            ->columns([
                ImageColumn::make('image_url')->label('')->circular()->imageSize(36),
                TextColumn::make('title')->limit(60)->wrap(),
                TextColumn::make('cheapest_price')
                    ->label('Now')
                    ->state(fn (Product $r): string => $r->currency . ' ' . ($r->cheapest_price ?? '—')),
                TextColumn::make('last_notified_price')
                    ->label('Notified at')
                    ->state(fn (Product $r): string => $r->currency . ' ' . ($r->last_notified_price ?? '—')),
                TextColumn::make('cheapestShop.host')
                    ->label('Shop')
                    ->placeholder('—'),
                TextColumn::make('last_notified_at')
                    ->label('Notified')
                    ->since()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Open')
                    ->url(fn (Product $r): string => ProductResource::getUrl('view', ['record' => $r])),
            ]);
    }

    /**
     * @return EloquentQueryBuilder<Product>
     */
    private function scopedQuery(): EloquentQueryBuilder
    {
        return Product::query()
            ->where('user_id', auth()->id())
            ->whereNotNull('last_notified_price')
            ->latest('last_notified_at');
    }
}
