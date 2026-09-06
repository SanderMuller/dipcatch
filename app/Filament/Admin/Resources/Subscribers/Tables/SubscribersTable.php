<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscribers\Tables;

use App\Billing\Plan;
use App\Billing\ProPrice;
use App\Billing\ProUsers;
use App\Models\User;
use App\Support\MoneyFormatter;
use App\Support\StripeDashboard;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscribersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // One query for the page: the subscription rows and the money
            // sum come along, so no column reaches back to the database or
            // to Stripe while rendering.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('subscriptions')
                ->withSum([
                    // One currency only, matching the widget: cents of two
                    // currencies do not add up to a number that means anything.
                    'stripePayments as revenue_sum' => fn (Builder $q): Builder => $q->where('currency', ProPrice::currency()),
                ], 'amount')
                ->withCount(['stripeDisputes as open_disputes_count' => fn (Builder $q): Builder => $q->whereNull('closed_at')]))
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $record): string => $record->name),

                TextColumn::make('status')
                    ->label('Plan')
                    ->badge()
                    ->state(fn (User $record): string => self::status($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Pro' => 'success',
                        'Trial' => 'info',
                        'Cancelling' => 'warning',
                        'Past due' => 'danger',
                        'Blocked' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('period_ends_at')
                    ->label('Renews or ends')
                    ->state(fn (User $record): ?string => self::periodEnd($record))
                    ->placeholder('—'),

                TextColumn::make('revenue_sum')
                    ->label('Net revenue')
                    ->state(fn (User $record): string => MoneyFormatter::formatMinor(self::amount($record, 'revenue_sum'), ProPrice::currency()))
                    ->sortable()
                    ->alignEnd(),

                IconColumn::make('open_disputes_count')
                    ->label('Dispute')
                    ->boolean()
                    ->state(fn (User $record): bool => self::amount($record, 'open_disputes_count') > 0)
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('pro')
                    ->label('Pro accounts')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('users.id', ProUsers::ids()),
                        false: fn (Builder $query): Builder => $query->whereNotIn('users.id', ProUsers::ids()),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Filter::make('has_billing')
                    ->label('Has a Stripe customer')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('stripe_id')),

                Filter::make('open_dispute')
                    ->label('Open chargeback')
                    ->query(fn (Builder $query): Builder => $query->whereHas('stripeDisputes', fn (Builder $q): Builder => $q->whereNull('closed_at'))),
            ])
            ->recordActions([
                Action::make('stripe')
                    ->label('Stripe')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (User $record): ?string => StripeDashboard::customerUrl($record->stripe_id))
                    ->openUrlInNewTab()
                    ->visible(fn (User $record): bool => $record->stripe_id !== null),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Aggregate columns arrive as untyped attributes from `withSum` and
     * `withCount`, so they are read, not cast.
     */
    private static function amount(User $record, string $attribute): int
    {
        $value = $record->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function status(User $record): string
    {
        if ($record->billing_blocked_at !== null) {
            return 'Blocked';
        }

        $subscription = $record->subscription(Plan::SUBSCRIPTION_TYPE);

        return match (true) {
            $subscription === null => 'Free',
            $subscription->onTrial() => 'Trial',
            $subscription->onGracePeriod() => 'Cancelling',
            $subscription->pastDue() => 'Past due',
            $subscription->valid() => 'Pro',
            default => 'Free',
        };
    }

    private static function periodEnd(User $record): ?string
    {
        $subscription = $record->subscription(Plan::SUBSCRIPTION_TYPE);

        if ($subscription === null) {
            return null;
        }

        $date = $subscription->ends_at ?? $subscription->trial_ends_at;

        return $date?->isoFormat('D MMM YYYY');
    }
}
