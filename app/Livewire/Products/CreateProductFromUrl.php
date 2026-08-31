<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Actions\Shops\ProbeOutcome;
use App\Enums\ScrapeStatus;
use App\Filament\App\Resources\Products\ProductResource;
use App\Livewire\Concerns\DrivesShopProbe;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Drops\TierDefaults;
use App\Support\UrlNormalizer;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;
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

        $rawPrice = $this->snapshot['price'] ?? '';
        $rawCurrency = $this->snapshot['currency'] ?? '';
        $snapshotPrice = is_string($rawPrice) ? $rawPrice : '';
        $snapshotCurrency = is_string($rawCurrency) ? $rawCurrency : '';
        $snapshotInStock = (bool) ($this->snapshot['in_stock'] ?? true);

        $usedManualSelector = $this->adapterKey === 'user-selector';
        $priceSelector = $usedManualSelector ? trim($this->priceSelector) : null;
        $titleSelector = $usedManualSelector ? (trim($this->titleSelector) ?: null) : null;
        $imageSelector = $usedManualSelector ? (trim($this->imageSelector) ?: null) : null;
        $variantKey = $this->chosenVariantKey;

        $product = DB::transaction(function () use (
            $snapshotPrice,
            $snapshotCurrency,
            $snapshotInStock,
            $priceSelector,
            $titleSelector,
            $imageSelector,
            $variantKey,
        ): Product {
            $product = Product::query()->create([
                'user_id' => auth()->id(),
                'title' => trim($this->title),
                'image_url' => trim($this->imageUrl) !== '' ? trim($this->imageUrl) : null,
                'currency' => $snapshotCurrency,
                'drop_threshold_pct' => $this->thresholdPct,
                'drop_threshold_abs' => $this->thresholdAbs,
                'active' => true,
            ]);

            $shop = $product->shops()->create([
                'url' => $this->normalizedUrl,
                'adapter_key' => $this->adapterKey,
                'price_selector' => $priceSelector,
                'title_selector' => $titleSelector,
                'image_selector' => $imageSelector,
                'variant_key' => $variantKey,
                'currency' => $snapshotCurrency,
                'initial_price' => $snapshotPrice,
                'initial_checked_at' => now(),
                'current_price' => $snapshotPrice,
                'current_in_stock' => $snapshotInStock,
                'last_checked_at' => now(),
                'last_success_at' => now(),
                'last_status' => ScrapeStatus::Ok->value,
            ]);

            $check = PriceCheck::create([
                'shop_id' => $shop->id,
                'price' => $snapshotPrice,
                'currency' => $snapshotCurrency,
                'in_stock' => $snapshotInStock,
                'status' => ScrapeStatus::Ok->value,
                'checked_at' => now(),
            ]);

            $product->recomputeCheapestShop((int) $check->id);

            return $product;
        });

        Notification::make()
            ->success()
            ->title('Product created')
            ->body("Now tracking {$product->title} on {$this->host}.")
            ->send();

        $this->redirect(ProductResource::getUrl('view', ['record' => $product], panel: 'app'));
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
