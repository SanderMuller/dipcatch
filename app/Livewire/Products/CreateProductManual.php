<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Models\Product;
use App\Models\User;
use App\Support\Iso4217;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;

/**
 * Track a product by hand.
 *
 * The documented fallback for a shop the scraper cannot read, so it is not an
 * admin affordance and does not disappear with the Filament panel.
 */
final class CreateProductManual extends Component
{
    use HasFluentValidation;

    public string $title = '';

    public string $image_url = '';

    public string $currency = 'EUR';

    public ?string $drop_threshold_pct = '10';

    public ?string $drop_threshold_abs = null;

    public ?string $limitMessage = null;

    /**
     * @return array<string, FluentRuleContract>
     */
    public function rules(): array
    {
        return [
            'title' => FluentRule::string('Title')->required()->max(255),
            'image_url' => FluentRule::httpUrl('Image URL')->nullable()->max(2048),
            'currency' => FluentRule::string('Currency')->required()->in(Iso4217::CODES),
            'drop_threshold_pct' => FluentRule::numeric('Drop threshold (%)')
                ->nullable()
                ->between(0, 100),
            'drop_threshold_abs' => FluentRule::numeric('Drop threshold (absolute)')->nullable()->min(0),
        ];
    }

    public function save(): void
    {
        // The currency is a free-text field here, not the listbox EditProduct
        // uses, so "eur" is a reasonable thing to type. Normalize before the
        // allowlist runs; a value that is not three letters is left as typed
        // so the rule reports it rather than an empty field.
        $this->currency = Iso4217::normalize($this->currency) ?? $this->currency;

        $this->validate();

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
