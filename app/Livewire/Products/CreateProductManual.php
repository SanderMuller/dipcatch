<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Track a product by hand.
 *
 * The documented fallback for a shop the scraper cannot read, so it is not an
 * admin affordance and does not disappear with the Filament panel.
 */
class CreateProductManual extends Component
{
    public string $title = '';

    public string $image_url = '';

    public string $currency = 'EUR';

    public ?string $drop_threshold_pct = '10';

    public ?string $drop_threshold_abs = null;

    public ?string $limitMessage = null;

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'currency' => ['required', 'string', 'size:3'],
            'drop_threshold_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'drop_threshold_abs' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $this->user();

        try {
            // The guard runs inside the transaction that writes the row, so two
            // tabs at the limit cannot both get through.
            $product = DB::transaction(function () use ($user): Product {
                app(PlanLimits::class)->guardProduct($user);

                return Product::query()->create([
                    'user_id' => $user->id,
                    'title' => $this->title,
                    'image_url' => $this->image_url !== '' ? $this->image_url : null,
                    'currency' => $this->currency,
                    'drop_threshold_pct' => $this->drop_threshold_pct,
                    'drop_threshold_abs' => $this->drop_threshold_abs,
                    'active' => true,
                ]);
            });
        } catch (PlanLimitReached $e) {
            // Stated on the page rather than thrown away: the person needs to
            // know why nothing was created.
            $this->limitMessage = $e->getMessage();

            return;
        }

        $this->redirectRoute('app.products.show', $product, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.products.create-product-manual');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
