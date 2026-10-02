<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Actions\Products\CategoriseProduct;
use App\Actions\Products\CreateProductWithShop;
use App\Actions\Products\ProductDraft;
use App\Actions\Products\SaveAlert;
use App\Actions\Products\UpdateProductDetails;
use App\Actions\Shops\ProbeOutcome;
use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Enums\TrackingIdea;
use App\Livewire\Concerns\DrivesShopProbe;
use App\Livewire\Concerns\EditsAlertFields;
use App\Livewire\Concerns\SuggestsAlert;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Services\Drops\Reference;
use App\Services\Drops\TierDefaults;
use App\Services\ShopDiscovery\WebShopDiscovery;
use App\Support\AlertSuggestion\AlertSuggestion;
use App\Support\ConnectedAssistant;
use App\Support\Iso4217;
use App\Support\UnitTargetGuide;
use App\Support\UnitWord;
use App\Support\UrlNormalizer;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;

/**
 * Adding a product, in three steps: the product (from a shop link, or by
 * hand), more shops, then the alert.
 *
 * Leaving step 1 saves the product: the shop suggestions and the add-shop
 * form both need one. A product left behind after that is tracked like any
 * other, on the default alert.
 */
final class AddProductWizard extends Component
{
    use DrivesShopProbe;
    use EditsAlertFields;
    use HasFluentValidation;
    use SuggestsAlert;

    /**
     * Steps 2 and 3 need a product, and step 1 shows it once there is one.
     * Locked: only goToStep() and the saves move it, so a browser cannot ask
     * for a step the page has no data for.
     */
    #[Url]
    #[Locked]
    public int $step = 1;

    /** The product leaving step 1 saved. Load it only through product(), which checks the owner. */
    #[Url(as: 'product', except: '')]
    #[Locked]
    public string $productId = '';

    /** Step 1 starts from a shop link, or from the fields filled in by hand. */
    #[Url(except: 'url')]
    public string $mode = 'url';

    /** The getting-started idea the person came from, for its shop hints. */
    #[Url(as: 'idea', except: '')]
    public string $idea = '';

    public string $title = '';

    public string $imageUrl = '';

    public string $manualTitle = '';

    public string $manualImageUrl = '';

    public string $currency = 'EUR';

    public ?string $limitMessage = null;

    /** "Set my own": the card steps aside for the fields. */
    public bool $settingOwn = false;

    /** What the last action did, for the page's status region: a screen reader hears it. */
    public string $status = '';

    /** @var array{id: string, title: string}|null Another product of this user already tracking the pasted URL. */
    public ?array $existingTrackedProduct = null;

    public function mount(): void
    {
        $this->mode = self::modeFrom($this->mode);

        if ($this->productId === '') {
            $this->step = 1;

            return;
        }

        $product = $this->product();
        $this->title = $product->title;
        $this->imageUrl = (string) $product->image_url;
        // From the product, so saving a product reopened later keeps what it
        // had rather than writing empty fields over it.
        $this->loadAlertFields($product);
        $this->step = max(1, min(3, $this->step));
    }

    protected function probeSubject(): null
    {
        return null;
    }

    /**
     * @return array<string, FluentRuleContract>
     */
    public function rules(): array
    {
        return [
            'title' => FluentRule::string('Title')->required()->max(255),
            'imageUrl' => FluentRule::httpUrl('Image URL')->nullable()->max(2048),
        ];
    }

    protected function onPreviewShown(ProbeOutcome $outcome): void
    {
        $snapshot = $this->snapshot ?? [];

        $title = $snapshot['title'] ?? '';
        $this->title = is_string($title) ? $title : '';

        $image = $snapshot['image_url'] ?? '';
        $this->imageUrl = is_string($image) ? $image : '';

        $this->existingTrackedProduct = null;
        if ($this->normalizedUrl !== null) {
            $existing = Shop::query()
                ->where('url_hash', UrlNormalizer::hash($this->normalizedUrl))
                ->whereHas('product', fn (EloquentQueryBuilder $query) => $query->where('user_id', auth()->id()))
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
        if ($this->productId !== '' || $this->state !== 'preview' || $this->snapshot === null
            || $this->normalizedUrl === null || $this->host === null
            || $this->adapterKey === null) {
            return;
        }

        $this->validate();

        // No alert input yet: that is step 3. Until then the default applies.
        $draft = new ProductDraft(title: trim($this->title), imageUrl: self::blankToNull($this->imageUrl));

        try {
            $product = app(CreateProductWithShop::class)($this->currentUser(), $draft, $this->shopDraft());
        } catch (PlanLimitReached $e) {
            $this->limitMessage = $e->getMessage();

            return;
        }

        $this->enterStepTwo($product);
    }

    public function saveManual(): void
    {
        if ($this->productId !== '') {
            return;
        }

        // "eur" is a fair thing to type. Anything that is not three letters
        // stays as typed, so the rule reports it.
        $this->currency = Iso4217::normalize($this->currency) ?? $this->currency;

        $this->validate([
            'manualTitle' => FluentRule::string('Title')->required()->max(255),
            'manualImageUrl' => FluentRule::httpUrl('Image URL')->nullable()->max(2048),
            'currency' => FluentRule::string('Currency')->required()->in(Iso4217::CODES),
        ]);

        $user = $this->currentUser();

        try {
            // The guard runs inside the transaction that writes the row, so
            // two tabs at the limit cannot both get through.
            $product = DB::transaction(function () use ($user): Product {
                app(PlanLimits::class)->guardProduct($user);

                return Product::query()->create([
                    'user_id' => $user->id,
                    'title' => trim($this->manualTitle),
                    'image_url' => self::blankToNull($this->manualImageUrl),
                    'currency' => $this->currency,
                    'active' => true,
                ]);
            });
        } catch (PlanLimitReached $e) {
            $this->limitMessage = $e->getMessage();

            return;
        }

        CategoriseProduct::afterResponseFor($product);

        $this->enterStepTwo($product);
    }

    public function saveDetails(UpdateProductDetails $update): void
    {
        $product = $this->product();

        $this->validate();

        $update($product, $this->title, self::blankToNull($this->imageUrl));

        $this->moveTo(2);
    }

    public function useShopImage(string $url): void
    {
        if (array_key_exists($url, UpdateProductDetails::shopImages($this->product()))) {
            $this->imageUrl = $url;
        }
    }

    /**
     * Starts web discovery for a product that has none yet, such as one saved
     * by hand: nothing else would until the nightly run.
     */
    #[On('shop-added')]
    public function shopAdded(WebShopDiscovery $discovery): void
    {
        $product = $this->product();

        if (! WebDiscovery::query()->whereKey($product->id)->exists()) {
            $discovery->queue($product);
        }
    }

    /** Leaving step 1 by any route saves the name and photo, as Next does. */
    public function goToStep(int $step, UpdateProductDetails $update): void
    {
        if ($this->productId === '' || $step < 1 || $step > 3) {
            return;
        }

        $product = $this->product();

        if ($this->step === 1) {
            $this->validate();
            $update($product, $this->title, self::blankToNull($this->imageUrl));
        }

        $this->moveTo($step);
    }

    public function switchMode(string $mode): void
    {
        if ($this->productId === '') {
            $this->mode = self::modeFrom($mode);
            $this->limitMessage = null;
            $this->focus($this->mode === 'manual' ? 'wizard-manual-title' : 'create-product-url');
        }
    }

    private static function modeFrom(string $mode): string
    {
        return $mode === 'manual' ? 'manual' : 'url';
    }

    public function useSuggestion(): void
    {
        $target = $this->alertSuggestion($this->product())->unitTarget;

        if ($target === null) {
            return;
        }

        $this->unitPriceTarget = $target;
        $this->dropThresholdPct = null;
        $this->dropThresholdAbs = null;
        $this->targetPrice = null;
        $this->chosenTarget = $target;
        $this->settingOwn = false;
        $this->status = __('Alert set to :target.', ['target' => $target]);
    }

    /**
     * Hides the card, and empties the target the card filled in — but not an
     * alert the product already had.
     */
    public function setOwn(): void
    {
        if ($this->chosenTarget !== null && $this->unitPriceTarget === $this->chosenTarget) {
            $this->unitPriceTarget = null;
        }

        $this->settingOwn = true;
        $this->chosenTarget = null;
        $this->focus('wizard-alert-fields');
    }

    public function showSuggestion(): void
    {
        $this->settingOwn = false;
        $this->focus('alert-suggestion-heading');
    }

    public function saveAlerts(SaveAlert $save): void
    {
        $product = $this->product();

        try {
            $this->validate($this->alertFieldRules());
        } catch (ValidationException $e) {
            // The fold keeps its own open state across round trips, so an
            // error in it would stay hidden in a closed one.
            if (array_intersect(array_keys($e->errors()), ['dropThresholdPct', 'dropThresholdAbs', 'targetPrice']) !== []) {
                $this->js("document.querySelector('[data-test=\"other-alerts\"]')?.setAttribute('open', '')");
            }

            throw $e;
        }

        $save($product, $this->alertFieldValues(), $this->chosenTarget);

        $this->finish();
    }

    protected function suggestedProduct(): Product
    {
        return $this->product();
    }

    protected function announce(string $message): void
    {
        $this->status = $message;
    }

    private function finish(): void
    {
        $product = $this->product();

        Notification::make()
            ->success()
            ->title('Product created')
            ->body("Now tracking {$product->title}.")
            ->send();

        $this->redirect(route('app.products.show', $product));
    }

    public function cancel(): void
    {
        $this->resetProbeState();
        $this->reset(['title', 'imageUrl', 'existingTrackedProduct', 'limitMessage']);
        $this->focus('create-product-url');
    }

    /**
     * The saved product, owned by the signed-in account. A missing, foreign or
     * malformed id is a 404: the id rides in the URL, and PostgreSQL refuses a
     * value that is not a UUID with an error rather than no row.
     */
    private function product(): Product
    {
        abort_unless(Str::isUuid($this->productId), 404);

        $product = Product::query()
            ->whereKey($this->productId)
            ->where('user_id', $this->currentUser()->id)
            ->with('shops')
            ->first();

        abort_unless($product instanceof Product, 404);

        return $product;
    }

    private function enterStepTwo(Product $product): void
    {
        $this->productId = (string) $product->id;
        $this->title = $product->title;
        $this->imageUrl = (string) $product->image_url;
        $this->limitMessage = null;
        $this->moveTo(2);
    }

    /** A step change moves focus to its heading, so a screen reader says where it is. */
    private function moveTo(int $step): void
    {
        $this->step = $step;
        $this->focus('wizard-step-heading');
    }

    private function focus(string $id): void
    {
        $this->js('document.getElementById(' . json_encode($id) . ')?.focus()');
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Product creation requires an authenticated user.');
        }

        return $user;
    }

    public function render(): View
    {
        $product = $this->productId === '' ? null : $this->product();

        return view('livewire.products.add-product-wizard', [
            'product' => $product,
            'connectedAssistant' => $product === null && $this->mode === 'url' && in_array($this->state, ['idle', 'error'], strict: true)
                ? ConnectedAssistant::nameFor($this->currentUser())
                : null,
            'trackingIdea' => TrackingIdea::tryFrom($this->idea),
            'shopImages' => $product === null ? [] : UpdateProductDetails::shopImages($product),
            'canAddShop' => $product !== null && app(PlanLimits::class)->canAddShop($product),
            'searchesWeb' => $product?->user?->wantsShopChecks() === true,
            'shopLimit' => $product?->user?->entitlements()->maxShopsPerProduct(),
            ...($product !== null && $this->step === 3 ? $this->alertStep($product) : []),
        ]);
    }

    /**
     * The drop defaults come from the same reference the drop check measures
     * from: for a product added during an offer, that is the offer price.
     *
     * @return array{suggestion: AlertSuggestion, onOfferNow: bool, asksJev: bool, usesJev: bool, canSwitchOnAi: bool, packChoices: list<array{shopId: string, host: string, pack: string, perPack: float, price: ?float, unitPrice: ?float}>, unitWord: ?string, defaults: array{pct: string, abs: string}|null, otherAlerts: list<string>}
     */
    private function alertStep(Product $product): array
    {
        $reference = app(Reference::class)->compute($product);
        $defaults = $reference === null ? null : TierDefaults::forReference($reference);

        return [
            ...$this->suggestionCard($product),
            'packChoices' => new UnitTargetGuide($product)->packs(),
            'unitWord' => UnitWord::noun($product->comparablePacks()->unit()),
            'defaults' => $defaults === null ? null : [
                'pct' => number_format($defaults['pct'], 2, '.', ''),
                'abs' => $defaults['abs'] === null ? '' : number_format($defaults['abs'], 2, '.', ''),
            ],
            'otherAlerts' => $this->otherAlertSummaries($product->currency),
        ];
    }
}
