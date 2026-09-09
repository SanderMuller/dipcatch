<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Actions\Products\CreateProductWithShop;
use App\Actions\Products\ProductDraft;
use App\Actions\Shops\ProbeOutcome;
use App\Billing\PlanLimitReached;
use App\Livewire\Concerns\DrivesShopProbe;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Drops\TierDefaults;
use App\Support\UrlNormalizer;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;
use RuntimeException;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;

/**
 * URL-first product creation: paste a shop URL, the probe fills
 * title/image/price/currency, tier defaults prefill the thresholds,
 * one Confirm creates Product + first Shop + initial PriceCheck.
 *
 * The probe state machine lives in DrivesShopProbe (create mode:
 * probeSubject() is null, so per-product dedupe and currency-mismatch
 * checks are skipped — the probed currency defines the product).
 */
class CreateProductFromUrl extends Component
{
    use DrivesShopProbe;
    use HasFluentValidation;

    public string $title = '';

    public string $imageUrl = '';

    public string $thresholdPct = '';

    public string $thresholdAbs = '';

    /** @var array{id: string, title: string}|null Another product of this user already tracking the pasted URL. */
    public ?array $existingTrackedProduct = null;

    protected function probeSubject(): ?Product
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => FluentRule::string('Title')->required()->max(255),
            'imageUrl' => FluentRule::url('Image URL')->nullable()->max(2048),
            'thresholdPct' => FluentRule::numeric('Drop threshold (%)')->required()->min(0.01)->max(99.99),
            'thresholdAbs' => FluentRule::numeric('Drop threshold (absolute)')->required()->min(0.01),
        ];
    }

    protected function onPreviewShown(ProbeOutcome $outcome): void
    {
        $snapshot = $this->snapshot ?? [];

        $title = $snapshot['title'] ?? '';
        $this->title = is_string($title) ? $title : '';

        $image = $snapshot['image_url'] ?? '';
        $this->imageUrl = is_string($image) ? $image : '';

        $price = $snapshot['price'] ?? '0';
        $defaults = TierDefaults::for(is_string($price) ? $price : '0');
        $this->thresholdPct = number_format($defaults['pct'], 2, '.', '');
        $this->thresholdAbs = number_format($defaults['abs'], 2, '.', '');

        $this->existingTrackedProduct = null;
        if ($this->normalizedUrl !== null) {
            $existing = Shop::query()
                ->where('url_hash', UrlNormalizer::hash($this->normalizedUrl))
                ->whereHas('product', fn (Builder $query) => $query->where('user_id', auth()->id()))
                ->with('product')
                ->first();

            if ($existing instanceof Shop && $existing->product !== null) {
                $this->existingTrackedProduct = [
                    'id' => (string) $existing->product->id,
                    'title' => $existing->product->title,
                ];
            }
        }
    }

    public function confirm(): void
    {
        if ($this->state !== 'preview' || $this->snapshot === null
            || $this->normalizedUrl === null || $this->host === null
            || $this->adapterKey === null) {
            return;
        }

        $this->validate();

        $shop = $this->shopDraft();

        $draft = new ProductDraft(
            title: trim($this->title),
            imageUrl: trim($this->imageUrl) !== '' ? trim($this->imageUrl) : null,
            dropThresholdPct: $this->thresholdPct,
            dropThresholdAbs: $this->thresholdAbs,
        );

        try {
            $product = app(CreateProductWithShop::class)($this->currentUser(), $draft, $shop);
        } catch (PlanLimitReached $e) {
            Notification::make()
                ->warning()
                ->title('Plan limit reached')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Product created')
            ->body("Now tracking {$product->title} on {$this->host}.")
            ->send();

        $this->redirect(route('app.products.show', $product));
    }

    /**
     * Livewire re-hydrates the component per request; the guard needs the
     * authenticated model, not the id.
     */
    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Product creation requires an authenticated user.');
        }

        return $user;
    }

    public function cancel(): void
    {
        $this->resetProbeState();
        $this->reset(['title', 'imageUrl', 'thresholdPct', 'thresholdAbs', 'existingTrackedProduct']);
    }

    public function render(): View
    {
        return view('livewire.products.create-product-from-url');
    }
}
