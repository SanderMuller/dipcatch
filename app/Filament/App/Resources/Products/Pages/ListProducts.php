<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Billing\PlanLimits;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /**
     * The limit is answered here so it shows before someone pastes a URL
     * and waits for a live fetch, only to be refused on Confirm.
     *
     * @return array<int, Action|CreateAction>
     */
    protected function getHeaderActions(): array
    {
        $canAdd = $this->canAddProduct();

        return [
            CreateAction::make()->visible($canAdd),

            Action::make('planLimit')
                ->label('Product limit reached')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('gray')
                ->tooltip(fn (): string => 'Your plan tracks up to ' . $this->productLimit() . ' products. Remove one, or upgrade for unlimited products.')
                ->url(url('/app/billing'))
                ->visible(! $canAdd),
        ];
    }

    private function canAddProduct(): bool
    {
        $user = auth()->user();

        return ! $user instanceof User || app(PlanLimits::class)->canAddProduct($user);
    }

    private function productLimit(): int
    {
        $user = auth()->user();

        return $user instanceof User ? ($user->entitlements()->maxProducts() ?? 0) : 0;
    }
}
