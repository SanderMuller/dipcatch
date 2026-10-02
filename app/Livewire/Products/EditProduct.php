<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Actions\Products\CategoriseProduct;
use App\Actions\Products\SaveAlert;
use App\Actions\Products\UpdateProductDetails;
use App\Enums\CategorySource;
use App\Enums\PriceDisplay;
use App\Enums\ProductCategory;
use App\Livewire\Concerns\EditsAlertFields;
use App\Livewire\Concerns\SuggestsAlert;
use App\Models\Product;
use App\Models\Shop;
use App\Services\ShopDiscovery\WebShopDiscovery;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\TypeSafeClient;
use App\Services\TypeSafe\TypeSafeRequestFailed;
use App\Support\Iso4217;
use App\Support\MoneyFormatter;
use App\Support\Numeric;
use App\Support\UnitTargetGuide;
use App\Support\UnitWord;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
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
    use EditsAlertFields;
    use HasFluentValidation;
    use SuggestsAlert;

    public Product $product;

    public string $title = '';

    public ?string $imageUrl = null;

    public string $currency = 'EUR';

    public bool $active = true;

    /** `pack`, `unit`, or empty for the automatic choice. */
    public string $priceDisplay = '';

    /** A `ProductCategory` value, or empty for no category. */
    public string $category = '';

    /**
     * The category as the form loaded it. The model is rehydrated from the
     * database on every request, so comparing against it would read a
     * category the after-response job wrote mid-edit as a clear.
     */
    public string $loadedCategory = '';

    public ?string $message = null;

    /** What the last action did, for the page's status region: a screen reader hears it. */
    public string $status = '';

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
        $this->loadAlertFields($product);
        $this->active = $product->active;
        $this->priceDisplay = $product->price_display->value ?? '';
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
            ...$this->alertFieldRules(),
            'category' => FluentRule::string('Category')->nullable()->in(ProductCategory::values()),
            'priceDisplay' => FluentRule::string('Show the price as')->nullable()->in(PriceDisplay::class),
        ];
    }

    /**
     * Replaces the product's own drop alert with a price alert at the same
     * saving. The drop fields empty, and with a target set an empty drop field
     * is off, so the price alert is the only alert left. Nothing is written
     * until the form is saved.
     */
    public function switchToPriceAlert(): void
    {
        $suggestion = new UnitTargetGuide($this->product)->switchFromDrop($this->dropThresholdPct, $this->dropThresholdAbs);

        if ($suggestion === null || self::blankToNull($this->unitPriceTarget) !== null) {
            return;
        }

        $this->unitPriceTarget = $suggestion['unit'];
        $this->dropThresholdPct = null;
        $this->dropThresholdAbs = null;
    }

    public function save(SaveAlert $saveAlert): void
    {
        $this->authorize('update', $this->product);

        $this->validate();

        // An untouched field is not a choice.
        if ($this->category !== $this->loadedCategory) {
            CategoriseProduct::chooseByUser($this->product, ProductCategory::tryFrom($this->category));
        }

        // A placed product carries no suggestion; this also clears one left
        // beside a category by an older write.
        if ($this->category !== '') {
            $this->product->forceFill(['suggested_category' => null]);
        }

        UpdateProductDetails::fill($this->product, $this->title, self::blankToNull($this->imageUrl));

        // One transaction, so the details never save without the alerts.
        DB::transaction(function () use ($saveAlert): void {
            $this->product->forceFill([
                'currency' => $this->currency,
                'active' => $this->active,
                'price_display' => PriceDisplay::tryFrom($this->priceDisplay),
            ])->save();

            $saveAlert($this->product, $this->alertFieldValues(), $this->chosenTarget);
        });

        // A new title changes what the web suggestions were checked against.
        app(WebShopDiscovery::class)->requeueIfStale($this->product);

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

        // Only onto the title Jev read, as in CategoriseProduct::store().
        if ($verdict->trackingIdea !== null) {
            Product::query()->whereKey($this->product->id)->where('title', $this->product->title)->update(['tracking_idea' => $verdict->trackingIdea->value]);
        }
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

    /** Fills in the suggested per-unit target. Nothing is written until the form is saved. */
    public function useSuggestion(): void
    {
        $target = $this->alertSuggestion($this->product)->unitTarget;

        if ($target === null) {
            return;
        }

        $this->unitPriceTarget = $target;
        $this->chosenTarget = $target;
        $this->announce(__('Alert set to :target. Save changes to keep it.', ['target' => $target]));
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->product);

        $this->product->delete();

        $this->redirectRoute('app.products.index', navigate: true);
    }

    /**
     * Use an image one of the shops reported, rather than making someone find
     * a URL by hand. Only one of those: the URL comes from the browser.
     */
    public function useShopImage(string $url): void
    {
        if (! array_key_exists($url, UpdateProductDetails::shopImages($this->product))) {
            return;
        }

        $this->imageUrl = $url;
        $this->message = 'Image taken from the shop. Save to keep it.';
    }

    public function render(): View
    {
        $guide = new UnitTargetGuide($this->product);
        $packChoices = $guide->packs();
        $alertCard = $this->product->unit_price_target === null && $packChoices !== [] ? $this->suggestionCard($this->product) : null;

        return view('livewire.products.edit-product', [
            'currencies' => Iso4217::options(),
            'categoryGroups' => ProductCategory::grouped(),
            'suggestionAvailable' => TypeSafeClient::configured(),
            'allowsAutoCategories' => $this->allowsAutoCategories(),
            'suggestedLabel' => ProductCategory::tryFrom((string) $this->suggestedCategory)?->label(),
            'shopImages' => UpdateProductDetails::shopImages($this->product),
            'packChoices' => $packChoices,
            'priceAlertSwitch' => self::blankToNull($this->unitPriceTarget) === null
                ? $guide->switchFromDrop($this->dropThresholdPct, $this->dropThresholdAbs, $packChoices)
                : null,
            'unitHistory' => $guide->history(),
            'comparisonUnit' => $this->product->comparablePacks()->unit(),
            ...$this->alertAnchors(),
            'alertCard' => $alertCard,
            // "In use" on the card, compared as numbers: the pack picker
            // writes 3.20 where the suggestion says 3.2.
            'suggestionInUse' => $alertCard !== null && $alertCard['suggestion']->unitTarget !== null && ($typed = self::blankToNull($this->unitPriceTarget)) !== null
                && is_numeric($typed) && bccomp(Numeric::str($typed), Numeric::str($alertCard['suggestion']->unitTarget), 4) === 0,
            'otherAlerts' => $this->otherAlertSummaries($this->currency),
        ]);
    }

    protected function suggestedProduct(): Product
    {
        return $this->product;
    }

    protected function announce(string $message): void
    {
        $this->status = $message;
    }

    private function allowsAutoCategories(): bool
    {
        return $this->product->user?->entitlements()->allowsAutoCategories() === true;
    }

    /**
     * The two figures a reader sets an alert against, and the sentence under
     * the per-unit field.
     *
     * Resolved together, from one read of the resolver and the same eligible
     * set the ranking uses. Asking each question separately let this form name
     * a dead shop as the best value while every other surface named a live one.
     *
     * @return array{unitWord: ?string, currentPrice: ?array{amount: string, host: string}, currentUnitPrice: ?array{amount: string, host: string}, unitTargetDescription: string}
     */
    private function alertAnchors(): array
    {
        $packs = $this->product->comparablePacks();
        $unitWord = UnitWord::noun($packs->unit());
        $bestValue = $this->product->bestValueShop();
        $unitPrice = $bestValue === null ? null : $packs->unitPriceOf($bestValue);

        return [
            'unitWord' => $unitWord,
            'currentPrice' => $this->anchor($this->product->lowestOutlayShop(), fn (Shop $shop): ?string => $shop->current_price === null
                ? null
                : (string) $shop->current_price),
            'currentUnitPrice' => $this->anchor(
                $bestValue,
                fn (): ?string => $unitPrice,
                UnitWord::labelFor($packs->unit()),
            ),
            'unitTargetDescription' => $this->unitTargetDescription($unitWord),
        ];
    }

    /**
     * One shop's price and host, formatted — or null when there is no figure a
     * reader could act on.
     *
     * @param  callable(Shop): ?string  $price
     * @return array{amount: string, host: string}|null
     */
    private function anchor(?Shop $shop, callable $price, string $suffix = ''): ?array
    {
        $amount = $shop === null ? null : $price($shop);

        if ($shop === null || $amount === null) {
            return null;
        }

        $currency = (string) $this->product->currency;

        return [
            // A per-unit anchor is what the reader types into the field beside
            // it, so it is shown at the precision the field accepts.
            'amount' => ($suffix === ''
                ? MoneyFormatter::format($amount, $currency)
                : MoneyFormatter::unitPrice($amount, $currency) . ' ' . $suffix),
            'host' => (string) $shop->host,
        ];
    }

    /** The sentence under the per-unit target field. */
    private function unitTargetDescription(?string $unitWord): string
    {
        return $unitWord === null
            ? __('We tell you when the best value reaches this price. The unit shows up here once a shop says how much is in the pack.')
            : __('We tell you when the best value reaches this price per :unit.', ['unit' => $unitWord]);
    }
}
