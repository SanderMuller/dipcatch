<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Jobs\CategoriseExistingProduct;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The AI features a person switches on themselves. Both choices are stored on
 * any plan; entitlements decide whether they do anything, so a choice made on
 * a trial survives the downgrade.
 */
#[Title('Product features')]
final class ProductFeatures extends Component
{
    public bool $auto_categories = false;

    public bool $shop_checks = false;

    public function mount(): void
    {
        $user = $this->user();

        $this->auto_categories = (bool) $user->auto_categories;
        $this->shop_checks = (bool) $user->shop_checks;
    }

    public function save(): void
    {
        $user = $this->user();
        $switchedOnCategories = $this->auto_categories && ! $user->auto_categories;

        $user->forceFill([
            'auto_categories' => $this->auto_categories,
            'shop_checks' => $this->shop_checks,
        ])->save();

        // Sorts the products already here now, rather than at the nightly run.
        $queued = $switchedOnCategories ? CategoriseExistingProduct::queueFor($user) : 0;

        Flux::toast(variant: 'success', text: $queued > 0
            ? __('Saved. DipCatch is sorting your products without a category now.')
            : __('Saved.'));
    }

    public function render(): View
    {
        $entitlements = $this->user()->entitlements();

        return view('livewire.settings.product-features', [
            'available' => TypeSafeClient::configured(),
            'allowsAutoCategories' => $entitlements->allowsAutoCategories(),
            'allowsShopChecks' => $entitlements->allowsShopChecks(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
