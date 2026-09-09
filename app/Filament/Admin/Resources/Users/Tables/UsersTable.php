<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Actions\Users\DeleteUser;
use App\Billing\Plan;
use App\Billing\ProUsers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The Plan column calls `plan()`, which reads the subscription
            // relation. Without the eager load that is a query per row, and
            // this table lists every account rather than only subscribers.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('subscriptions')
                ->withCount('products'))
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $record): string => $record->name),

                TextColumn::make('plan')
                    ->badge()
                    ->state(fn (User $record): string => $record->plan()->label())
                    ->color(fn (string $state): string => $state === Plan::Pro->label() ? 'success' : 'gray'),

                TextColumn::make('entitlement')
                    ->label('Why')
                    ->state(self::reason(...))
                    ->color('gray'),

                TextColumn::make('comped_until')
                    ->label('Comped until')
                    ->state(fn (User $record): ?string => self::compLabel($record))
                    ->description(fn (User $record): ?string => $record->comped_reason)
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->sortable()
                    ->alignEnd(),

                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('pro')
                    ->label('Entitled to Pro')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('users.id', ProUsers::ids()),
                        false: fn (Builder $query): Builder => $query->whereNotIn('users.id', ProUsers::ids()),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Filter::make('comped')
                    ->label('Comped')
                    // Blocked accounts are not entitled, so they are not comped
                    // either — the same precedence `User::isComped()` applies.
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNull('billing_blocked_at')
                        ->where('comped_until', '>', now())),

                Filter::make('blocked')
                    ->label('Billing blocked')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('billing_blocked_at')),

                Filter::make('admin')
                    ->label('Admins')
                    ->query(fn (Builder $query): Builder => $query->where('is_admin', true)),
            ])
            ->recordActions([
                Action::make('comp')
                    ->label('Comp')
                    ->icon('heroicon-o-gift')
                    ->color('success')
                    // The panel gate already requires an admin. Checked again
                    // here so the action stays safe if it is ever reused
                    // outside this panel.
                    ->visible(fn (): bool => self::actorIsAdmin())
                    ->schema(self::compForm(...))
                    ->action(self::grantComp(...)),

                Action::make('endComp')
                    ->label('End comp')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The account drops to Free immediately. History already kept stays kept.')
                    ->visible(fn (User $record): bool => self::actorIsAdmin() && $record->isComped())
                    ->action(self::endComp(...)),

                DeleteAction::make()
                    ->modalDescription('The account, its products, its price history and its notifications go. A live subscription is cancelled in Stripe first. Payment and dispute records stay, without the account.')
                    // Deleting yourself ends your own session halfway through
                    // the request, so the action is not offered on your row.
                    ->visible(fn (User $record): bool => self::actorIsAdmin() && ! $record->is(auth()->user()))
                    // False picks Filament's failure notification. Without the
                    // catch a Stripe error escapes into Livewire instead.
                    ->using(function (User $record): bool {
                        try {
                            app(DeleteUser::class)($record);
                        } catch (Throwable $exception) {
                            report($exception);

                            return false;
                        }

                        return true;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(self::actorIsAdmin(...))
                        ->using(self::deleteMany(...)),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function compForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('duration')
                ->label('For how long')
                ->options([
                    'month' => 'One month',
                    'year' => 'One year',
                    'forever' => 'Forever',
                ])
                ->default('year')
                ->required()
                ->selectablePlaceholder(false),

            TextInput::make('reason')
                ->label('Why')
                ->helperText('Recorded on the account. A comp with no reason becomes a mystery.')
                ->required()
                ->maxLength(255),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function grantComp(User $record, array $data): void
    {
        $duration = is_string($data['duration'] ?? null) ? $data['duration'] : 'year';
        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';

        $record->forceFill([
            'comped_until' => match ($duration) {
                'month' => CarbonImmutable::now()->addMonth(),
                'forever' => CarbonImmutable::parse(Plan::COMPED_FOREVER),
                default => CarbonImmutable::now()->addYear(),
            },
            'comped_reason' => $reason,
        ])->save();

        self::log('granted', $record, $reason);
    }

    /**
     * @param  Collection<int, User>  $records
     */
    private static function deleteMany(DeleteBulkAction $action, Collection $records): void
    {
        foreach ($records as $record) {
            // Same reason as the single action: an admin does not delete the
            // account they are signed in with. Reported as a failure so the
            // count Filament shows matches what was actually deleted.
            if ($record->is(auth()->user())) {
                $action->reportBulkProcessingFailure();

                continue;
            }

            try {
                app(DeleteUser::class)($record);
            } catch (Throwable $exception) {
                // One account that Stripe refuses to cancel must not stop the
                // rest of the selection.
                $action->reportBulkProcessingFailure();

                report($exception);
            }
        }
    }

    public static function endComp(User $record): void
    {
        self::log('ended', $record, (string) $record->comped_reason);

        $record->forceFill(['comped_until' => null, 'comped_reason' => null])->save();
    }

    /**
     * Which branch of `Subscribes::plan()` granted this account its plan.
     */
    private static function reason(User $record): string
    {
        if ($record->billing_blocked_at !== null) {
            return 'Blocked';
        }

        if ($record->isComped()) {
            return 'Comp';
        }

        if ($record->subscription(Plan::SUBSCRIPTION_TYPE) !== null) {
            return 'Subscription';
        }

        return $record->onTrial() ? 'Trial' : '—';
    }

    private static function compLabel(User $record): ?string
    {
        if ($record->comped_until === null) {
            return null;
        }

        if (! $record->comped_until->isFuture()) {
            return 'Expired';
        }

        return $record->comped_until->toDateString() === CarbonImmutable::parse(Plan::COMPED_FOREVER)->toDateString()
            ? 'Forever'
            : $record->comped_until->toDateString();
    }

    private static function actorIsAdmin(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->is_admin === true;
    }

    private static function log(string $verb, User $record, string $reason): void
    {
        $actor = auth()->user();

        Log::info('Comp ' . $verb, [
            'user_id' => $record->getKey(),
            'by' => $actor instanceof User ? $actor->getKey() : null,
            'reason' => $reason,
        ]);
    }
}
