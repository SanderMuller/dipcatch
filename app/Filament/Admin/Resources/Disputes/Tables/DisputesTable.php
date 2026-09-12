<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Disputes\Tables;

use App\Models\StripeDispute;
use App\Support\MoneyFormatter;
use App\Support\StripeDashboard;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('user.email')
                    ->label('Customer')
                    ->placeholder('Not linked')
                    ->searchable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn (StripeDispute $record): string => MoneyFormatter::formatMinor($record->amount, $record->currency))
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    // Stripe's own vocabulary — `needs_response`,
                    // `warning_closed` — reads as machine output in a table.
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->color(fn (StripeDispute $record): string => match (true) {
                        $record->isLost() => 'danger',
                        $record->status === StripeDispute::STATUS_WON => 'success',
                        default => 'warning',
                    }),

                TextColumn::make('reason')
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('opened_at')
                    ->label('Opened')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('closed_at')
                    ->label('Closed')
                    ->dateTime()
                    ->placeholder('Open')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('open')
                    ->label('Open only')
                    ->query(fn (Builder $query): Builder => $query->whereNull('closed_at'))
                    ->default(),
            ])
            ->recordActions([
                Action::make('stripe')
                    ->label('Stripe')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (StripeDispute $record): ?string => StripeDashboard::disputeUrl($record->stripe_id))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No chargebacks')
            ->emptyStateDescription('Nothing has been disputed. This queue fills itself from Stripe.')
            ->defaultSort('opened_at', 'desc');
    }
}
