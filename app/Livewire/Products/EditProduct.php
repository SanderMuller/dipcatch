<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Models\Product;
use App\Support\Iso4217;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;

/**
 * Everything about a product except its shops: what it is called, which
 * image represents it, and when it should alert.
 *
 * The shops have their own controls on the product page, because adding one
 * costs a live fetch and this form does not.
 */
class EditProduct extends Component
{
    use HasFluentValidation;

    public Product $product;

    public string $title = '';

    public ?string $imageUrl = null;

    public string $currency = 'EUR';

    public ?string $dropThresholdPct = null;

    public ?string $dropThresholdAbs = null;

    public ?string $targetPrice = null;

    public ?string $unitPriceTarget = null;

    public bool $active = true;

    public ?string $message = null;

    public function mount(Product $product): void
    {
        // Route-model binding hands over any id in the URL, so ownership is
        // checked here rather than left to a scoped query.
        $this->authorize('update', $product);

        $this->product = $product;
        $this->title = $product->title;
        $this->imageUrl = $product->image_url;
        $this->currency = $product->currency;
        $this->dropThresholdPct = $product->drop_threshold_pct === null ? null : (string) $product->drop_threshold_pct;
        $this->dropThresholdAbs = $product->drop_threshold_abs === null ? null : (string) $product->drop_threshold_abs;
        $this->targetPrice = $product->target_price === null ? null : (string) $product->target_price;
        $this->unitPriceTarget = $product->unit_price_target === null ? null : (string) $product->unit_price_target;
        $this->active = $product->active;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => FluentRule::string('Title')->required()->max(255),
            'imageUrl' => FluentRule::url('Image URL')->nullable()->max(2048),
            'currency' => FluentRule::string('Currency')->required()->in(Iso4217::CODES),
            // A threshold of zero would alert on a price that did not move.
            'dropThresholdPct' => FluentRule::numeric('Drop threshold (%)')->nullable()->min(0.01)->max(99.99),
            'dropThresholdAbs' => FluentRule::numeric('Drop threshold (absolute)')->nullable()->min(0.01),
            'targetPrice' => FluentRule::numeric('Target price')->nullable()->min(0.01),
            'unitPriceTarget' => FluentRule::numeric('Unit price target')->nullable()->min(0.01),
        ];
    }

    public function save(): void
    {
        $this->authorize('update', $this->product);

        $this->validate();

        $this->product->forceFill([
            'title' => trim($this->title),
            'image_url' => $this->blankToNull($this->imageUrl),
            'currency' => $this->currency,
            'drop_threshold_pct' => $this->blankToNull($this->dropThresholdPct),
            'drop_threshold_abs' => $this->blankToNull($this->dropThresholdAbs),
            'target_price' => $this->blankToNull($this->targetPrice),
            // Stored on any plan and kept on downgrade. DetectUnitPriceTarget
            // decides whether it may alert, so a Pro trial that lapses does
            // not silently throw the number away.
            'unit_price_target' => $this->blankToNull($this->unitPriceTarget),
            'active' => $this->active,
        ])->save();

        $this->redirectRoute('app.products.show', $this->product, navigate: true);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->product);

        $this->product->delete();

        $this->redirectRoute('app.products.index', navigate: true);
    }

    /**
     * Use an image one of the shops reported, rather than making someone find
     * a URL by hand.
     *
     * Addressed by position: a URL written into a `wire:click` attribute
     * breaks the page's JavaScript as soon as it contains a quote.
     */
    public function useShopImage(int $index): void
    {
        $url = array_keys($this->shopImages())[$index] ?? null;

        if ($url === null) {
            return;
        }

        $this->imageUrl = $url;
        $this->message = 'Image taken from the shop. Save to keep it.';
    }

    public function render(): View
    {
        return view('livewire.products.edit-product', [
            'currencies' => Iso4217::options(),
            'shopImages' => $this->shopImages(),
            'allowsUnitPriceAlerts' => $this->product->user?->entitlements()->allowsUnitPriceAlerts() === true,
        ]);
    }

    /**
     * Distinct images the shops reported, newest check first.
     *
     * @return array<string, string>
     */
    private function shopImages(): array
    {
        $images = [];

        foreach ($this->product->shops as $shop) {
            $url = $shop->safeImageUrl();

            if ($url !== null && ! isset($images[$url])) {
                $images[$url] = $shop->host ?? '';
            }
        }

        return $images;
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed === '' ? null : $trimmed;
    }
}
