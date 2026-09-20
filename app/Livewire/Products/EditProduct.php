<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\TypeSafeClient;
use App\Services\TypeSafe\TypeSafeRequestFailed;
use App\Support\Iso4217;
use App\Support\MoneyFormatter;
use App\Support\UnitWord;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;

/**
 * Everything about a product except its shops: what it is called, which
 * image represents it, and when it should alert.
 *
 * The shops have their own controls on the product page, because adding one
 * costs a live fetch and this form does not.
 */
final class EditProduct extends Component
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

    /** A `ProductCategory` value, or empty for no category. */
    public string $category = '';

    /**
     * The category as the form loaded it. The model is rehydrated from the
     * database on every request, so comparing against it would read a
     * category the after-response job wrote mid-edit as a clear.
     */
    public string $loadedCategory = '';

    public ?string $message = null;

    /** A category Jev proposed, as a `ProductCategory` value, until accepted or declined. */
    public ?string $suggestedCategory = null;

    public ?string $suggestionMessage = null;

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
        $this->category = $product->category->value ?? '';
        $this->loadedCategory = $this->category;
        $this->suggestedCategory = $this->category === '' ? ($product->suggested_category->value ?? null) : null;
    }

    /**
     * @return array<string, FluentRuleContract>
     */
    public function rules(): array
    {
        return [
            'title' => FluentRule::string('Title')->required()->max(255),
            'imageUrl' => FluentRule::httpUrl('Image URL')->nullable()->max(2048),
            'currency' => FluentRule::string('Currency')->required()->in(Iso4217::CODES),
            // A threshold of zero would alert on a price that did not move.
            'dropThresholdPct' => FluentRule::numeric('Alert me when it drops by (%)')
                ->nullable()
                ->between(0.01, 99.98999999999999),
            'dropThresholdAbs' => FluentRule::numeric('Alert me when it drops by (amount)')->nullable()->min(0.01),
            'targetPrice' => FluentRule::numeric('Target price')->nullable()->min(0.01),
            'unitPriceTarget' => FluentRule::numeric('Target price per kilo, litre or piece')->nullable()->min(0.01),
            'category' => FluentRule::string('Category')->nullable()->in(ProductCategory::values()),
        ];
    }

    public function save(): void
    {
        $this->authorize('update', $this->product);

        $this->validate();

        // A choice, a clear included, is final; an untouched field is not a choice.
        if ($this->category !== $this->loadedCategory) {
            $this->product->forceFill([
                'category' => ProductCategory::tryFrom($this->category),
                'category_set_by' => CategorySource::User,
            ]);
        }

        if ($this->category !== '') {
            $this->product->forceFill(['suggested_category' => null]);
        }

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

    /**
     * One request on the person's own click, kept on the product so the next
     * visit shows it without asking again. Guards match the settings switch,
     * a Pro plan and a configured key; the opt-in is not needed for an
     * explicit ask.
     */
    public function suggestCategory(): void
    {
        $this->authorize('update', $this->product);
        $this->suggestionMessage = null;

        if (! TypeSafeClient::configured() || ! $this->allowsAutoCategories()) {
            return;
        }

        if ($this->product->suggested_category !== null) {
            $this->suggestedCategory = $this->product->suggested_category->value;

            return;
        }

        if ($this->product->user === null || ! app(CategorisationBudget::class)->allows($this->product->user)) {
            $this->suggestionMessage = __('You have used today\'s suggestions. Try again tomorrow.');

            return;
        }

        try {
            $verdict = app(TypeSafeClient::class)->categorise($this->product);
        } catch (TypeSafeRequestFailed $e) {
            Log::warning('Category suggestion failed.', ['product_id' => $this->product->id, 'error' => $e->getMessage(), 'exception' => $e]);
            $this->suggestionMessage = __('No suggestion right now. Try again in a moment.');

            return;
        }

        if ($verdict->winner === null) {
            $this->suggestionMessage = __('Nothing fits this product well enough to suggest.');

            return;
        }

        $this->suggestedCategory = $verdict->winner->value;
        $this->product->forceFill(['suggested_category' => $verdict->winner])->save();
    }

    public function acceptSuggestion(): void
    {
        if ($this->suggestedCategory === null) {
            return;
        }

        $this->category = $this->suggestedCategory;
        $this->suggestedCategory = null;
        $this->message = __('Category set. Save changes to keep it.');
    }

    /**
     * A decline is a decision, the same as clearing the select: the source
     * becomes the person's, so neither the after-response sort nor the backfill
     * asks for this product again. An explicit "Suggest" click still can.
     */
    public function declineSuggestion(): void
    {
        $this->suggestedCategory = null;
        $this->product->forceFill(['suggested_category' => null, 'category_set_by' => CategorySource::User])->save();
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
            'categoryGroups' => ProductCategory::grouped(),
            'suggestionAvailable' => TypeSafeClient::configured(),
            'allowsAutoCategories' => $this->allowsAutoCategories(),
            'suggestedLabel' => ProductCategory::tryFrom((string) $this->suggestedCategory)?->label(),
            'shopImages' => $this->shopImages(),
            'allowsUnitPriceAlerts' => $this->product->user?->entitlements()->allowsUnitPriceAlerts() === true,
            'unitWord' => $this->unitWord(),
            'currentPrice' => $this->currentLowestPrice(),
            'currentUnitPrice' => $this->currentBestUnitPrice(),
        ]);
    }

    private function allowsAutoCategories(): bool
    {
        return $this->product->user?->entitlements()->allowsAutoCategories() === true;
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

    /**
     * What the product costs today at the shop with the smallest outlay, and
     * where — the figure a target price is set against.
     *
     * Null when no shop has a usable price, where a "now" line would be a
     * number nobody can act on.
     *
     * @return array{amount: string, host: string}|null
     */
    private function currentLowestPrice(): ?array
    {
        $shop = $this->product->cheapestShop;

        if ($shop === null || $shop->current_price === null) {
            return null;
        }

        return [
            'amount' => MoneyFormatter::format((string) $shop->current_price, (string) $this->product->currency),
            'host' => (string) $shop->host,
        ];
    }

    /**
     * The best price per unit on offer today, and where. The anchor for a
     * per-unit target, which is a different question and often a different
     * shop from the one above.
     *
     * @return array{amount: string, host: string}|null
     */
    private function currentBestUnitPrice(): ?array
    {
        $packs = $this->product->comparablePacks();
        $shop = $packs->cheapestPerUnit($this->product->shops);
        $unitPrice = $shop === null ? null : $packs->unitPriceOf($shop);

        if ($shop === null || $unitPrice === null) {
            return null;
        }

        return [
            'amount' => MoneyFormatter::format($unitPrice, (string) $this->product->currency)
                . UnitWord::labelFor($packs->unit()),
            'host' => (string) $shop->host,
        ];
    }

    /**
     * The unit this product's alert compares in, named rather than listed.
     * Null while no shop has read a pack size, when the reader really does
     * not know yet and neither do we.
     *
     * Read from the resolver rather than from the majority pack unit, so the
     * label names the unit the alert actually fires on.
     */
    private function unitWord(): ?string
    {
        $word = match ($this->product->comparablePacks()->unit()) {
            'g' => __('kilo'),
            'ml' => __('litre'),
            'piece' => __('piece'),
            default => null,
        };

        // `__()` is typed as array|string: a key that maps to an array is not
        // a word, and reads here as no unit at all.
        return is_string($word) ? $word : null;
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed === '' ? null : $trimmed;
    }
}
