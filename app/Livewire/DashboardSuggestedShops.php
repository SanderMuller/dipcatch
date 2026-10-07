<?php declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AiFeature;
use App\Livewire\Concerns\HidesShops;
use App\Models\Product;
use App\Models\User;
use App\Services\ShopDiscovery\DiscoveryReach;
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
    use HidesShops;

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
            'offersWebSearch' => ! $user->wantsShopChecks() && AiFeature::ShopChecks->planAllows($user) && $this->couldSearchFor($user),
        ]);
    }

    /** Whether discovery could search for one of the account's products. */
    private function couldSearchFor(User $user): bool
    {
        return app(DiscoveryReach::class)->covers('EUR')
            && Product::query()->where('user_id', $user->id)->where('currency', 'EUR')->exists();
    }
}
