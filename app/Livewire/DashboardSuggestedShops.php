<?php declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Support\DashboardSuggestions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Suggested shops across the account's products, on the dashboard. Lazy,
 * because matching the daily dataset takes longer than the rest of the page.
 */
#[Lazy]
final class DashboardSuggestedShops extends Component
{
    public function placeholder(): View
    {
        return view('livewire.dashboard-suggested-shops-placeholder');
    }

    public function render(DashboardSuggestions $suggestions): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.dashboard-suggested-shops', [
            'rows' => $suggestions->forUser($user),
            'aiChecked' => $user->wantsShopChecks(),
        ]);
    }
}
