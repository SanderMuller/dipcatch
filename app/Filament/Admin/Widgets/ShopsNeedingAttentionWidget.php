<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\ShopHealth;
use App\Models\Shop;
use App\Support\Favicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\HtmlString;

/**
 * The offers the scraper can no longer read, worst first.
 *
 * This is the dashboard's one actionable list: a dead host usually means a
 * new adapter or a changed selector, and grouping the count by host is what
 * says which one is worth the work.
 */
class ShopsNeedingAttentionWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Offers needing attention')
            ->description('Active offers the scraper could not read. A repeated host is usually one broken adapter, not one broken URL.')
            ->emptyStateHeading('Every active offer is readable')
            ->emptyStateDescription('Nothing is failing or dead right now.')
            ->query($this->failingQuery())
            ->defaultPaginationPageOption(10)
            ->defaultSort('consecutive_failures', 'desc')
            ->columns([
                TextColumn::make('host')
                    ->label('Shop')
                    ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(Favicon::html($state)))
                    ->searchable(),
                TextColumn::make('health')
                    ->badge()
                    ->color(fn (ShopHealth $state): string => $state === ShopHealth::Dead ? 'danger' : 'warning'),
                TextColumn::make('last_status')
                    ->label('Last status')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('consecutive_failures')
                    ->label('Failures')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('last_error')
                    ->label('Error')
                    ->limit(60)
                    ->tooltip(fn (Shop $record): ?string => $record->last_error)
                    ->placeholder('—')
                    ->visibleFrom('lg'),
                TextColumn::make('last_success_at')
                    ->label('Last read')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),
            ]);
    }

    /**
     * @return EloquentQueryBuilder<Shop>
     */
    private function failingQuery(): EloquentQueryBuilder
    {
        return Shop::query()
            ->where('active', true)
            // A paused product's offers are not rechecked at all, so listing
            // them here would ask the owner to fix something the app has
            // deliberately stopped doing. Matches the scheduler's own filter.
            ->whereHas('product', fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('active', true))
            ->whereIn('health', [ShopHealth::Failing->value, ShopHealth::Dead->value]);
    }
}
