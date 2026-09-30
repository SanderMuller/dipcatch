<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Shops\ProbeOutcome;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\ShopDraft;
use App\Models\FailedShopPage;
use App\Models\Product;
use App\Models\User;
use App\PriceAdapters\ShopSnapshot;
use App\PriceAdapters\VariantCandidate;
use App\Support\BundlePriceLabel;
use App\Support\ImageUrl;
use App\Support\PackSize;
use Livewire\Attributes\Locked;

/**
 * Probe-driving state machine shared by the Add-Shop form (existing
 * product) and the Create-Product-From-URL form (no product yet).
 *
 * States: 'idle' | 'preview' | 'error' | 'manual_selector' | 'variant_chooser'
 *  - no_adapter_matched      → flips to manual_selector
 *  - multiple_variants found → flips to variant_chooser
 */
trait DrivesShopProbe
{
    public string $url = '';

    /** One of: 'idle' | 'preview' | 'error' | 'manual_selector' | 'variant_chooser' */
    public string $state = 'idle';

    /** @var array<string, mixed>|null Snapshot data after a successful probe. */
    public ?array $snapshot = null;

    /** @var list<string> The page's product photos for the preview, main one first. */
    #[Locked]
    public array $imageUrls = [];

    public ?string $normalizedUrl = null;

    public ?string $host = null;

    public ?string $adapterKey = null;

    public ?string $errorCode = null;

    /** @var array<string, mixed>|null */
    public ?array $errorContext = null;

    public string $priceSelector = '';

    public string $titleSelector = '';

    public string $imageSelector = '';

    public string $manualCurrency = 'EUR';

    /** @var list<array{key: string, title: string, price: string, currency: string}>|null */
    public ?array $variants = null;

    public ?string $chosenVariantKey = null;

    /** True when the probe picked the variant itself, from the product's pack size. */
    public bool $variantPicked = false;

    /**
     * The product the probe dedupes and currency-checks against, or null
     * in create mode (the probed currency then defines the product).
     */
    abstract protected function probeSubject(): ?Product;

    /**
     * Authorizes the hydrated subject. Livewire re-hydrates a public property
     * from the request on every call without authorizing it, so every public
     * action here runs this before it touches component state. Create mode has
     * no subject and leaves it empty.
     */
    protected function authorizeProbeSubject(): void {}

    /**
     * The currency manual entry starts from. An existing product fixes it;
     * create mode has no product, so the probed currency defines it later.
     */
    protected function defaultManualCurrency(): string
    {
        return 'EUR';
    }

    public function probe(ProbeShopUrl $probe): void
    {
        $this->runProbe($probe);
    }

    public function probeWithSelectors(ProbeShopUrl $probe): void
    {
        // Authorize before the early return below: a public Livewire action
        // must not touch component state for a product the caller cannot see.
        $this->authorizeProbeSubject();

        $price = trim($this->priceSelector);
        if ($price === '') {
            $this->errorCode = 'user_selector_required';
            $this->errorContext = null;

            return;
        }

        // Each selector is stored in a 255-character column.
        if (array_any([$price, trim($this->titleSelector), trim($this->imageSelector)], static fn (string $selector): bool => mb_strlen($selector) > 255)) {
            $this->errorCode = 'user_selector_too_long';
            $this->errorContext = null;

            return;
        }

        $this->runProbe($probe, [
            'price' => $price,
            'title' => trim($this->titleSelector) ?: null,
            'image' => trim($this->imageSelector) ?: null,
        ], $this->manualCurrency);
    }

    public function selectVariant(ProbeShopUrl $probe): void
    {
        $this->authorizeProbeSubject();

        if ($this->chosenVariantKey === null || $this->chosenVariantKey === '') {
            return;
        }

        $this->runProbe($probe, variantKey: $this->chosenVariantKey);
    }

    public function showManualSelector(): void
    {
        $this->authorizeProbeSubject();

        $this->state = 'manual_selector';
        $this->errorCode = null;
        $this->errorContext = null;
    }

    /**
     * @param  array{price?: ?string, title?: ?string, image?: ?string}  $selectors
     */
    private function runProbe(
        ProbeShopUrl $probe,
        array $selectors = [],
        ?string $currency = null,
        ?string $variantKey = null,
    ): void {
        $this->authorizeProbeSubject();
        $this->resetPreview();
        // The key this probe asks for, and no other: a choice made for an
        // earlier URL must not be saved against this one.
        $this->chosenVariantKey = $variantKey;
        $url = trim($this->url);

        if ($url === '') {
            $this->failWith('empty_url', context: null);

            return;
        }

        /** @var User|null $actor */
        $actor = auth()->user();
        if (! $actor instanceof User) {
            $this->failWith('unauthenticated', context: null);

            return;
        }

        // Rebuilt as a constant array of narrowed values: the probe's
        // `$selectors` is a sealed shape, and reading a trait parameter gives
        // no shape back, so each key is checked rather than assumed.
        $selectors = [
            'price' => self::selector($selectors, 'price'),
            'title' => self::selector($selectors, 'title'),
            'image' => self::selector($selectors, 'image'),
        ];
        $outcome = $probe($this->probeSubject(), $url, $actor, $selectors, $currency, $variantKey);
        FailedShopPage::recordOutcome($url, $outcome, $actor, $selectors);

        match (true) {
            $outcome->isSuccess() => $this->showPreview($outcome),
            $outcome->isDuplicate() => $this->failWith('duplicate', [
                'existing_shop_host' => $outcome->existingShop?->host,
            ]),
            $outcome->isAmbiguous() => $this->showVariantChooser($outcome),
            default => $this->handleFailure($outcome),
        };
    }

    /**
     * Hook for consumers that need to derive extra state from a
     * successful probe (e.g. prefill editable create-form fields).
     */
    protected function onPreviewShown(ProbeOutcome $outcome): void {}

    /**
     * Hook for consumers that derived state from a preview, to drop it when
     * the preview goes: a new probe, or a reset after a confirm.
     */
    protected function onProbeReset(): void {}

    private function showVariantChooser(ProbeOutcome $outcome): void
    {
        $this->state = 'variant_chooser';
        $this->normalizedUrl = $outcome->normalizedUrl;
        $this->host = $outcome->host;
        $this->variants = self::variantRows($outcome);
        $this->chosenVariantKey ??= $this->variants[0]['key'] ?? null;
    }

    /**
     * Back to the chooser from a preview whose variant the probe picked.
     */
    public function chooseAnotherVariant(): void
    {
        $this->authorizeProbeSubject();

        if ($this->variants === null || $this->variants === []) {
            return;
        }

        $this->state = 'variant_chooser';
        $this->variantPicked = false;
    }

    /**
     * @return list<array{key: string, title: string, price: string, currency: string}>
     */
    private static function variantRows(ProbeOutcome $outcome): array
    {
        return array_map(
            static fn (VariantCandidate $v): array => [
                'key' => $v->key,
                'title' => $v->title,
                'price' => $v->price,
                'currency' => $v->currency,
            ],
            $outcome->variants,
        );
    }

    /** The URL Confirm saves. Http(s) only: `normalizedUrl` is a public property the client can send back. */
    public function previewPageUrl(): ?string
    {
        return ImageUrl::safe($this->normalizedUrl);
    }

    /**
     * The preview templates show the pack size before anything is written.
     * Read off the draft, so there is one parse rather than two.
     */
    public function snapshotPackSize(): ?PackSize
    {
        return $this->shopDraft()->packSize;
    }

    /**
     * The previewed bundle in the shop's words, on the same terms the draft
     * will be written under — a promotion date that does not parse drops it.
     */
    public function previewBundleLabel(): ?string
    {
        $draft = $this->shopDraft();

        return $draft->bundleOffer === null
            ? null
            : BundlePriceLabel::forTerms($draft->bundleOffer, $draft->currency, $draft->singleItemPrice ?? $draft->price, $draft->promotionWindow);
    }

    /**
     * True when the previewed price is the bundle's unit price, so the
     * single-item price beside it is the regular one worth striking through.
     */
    public function previewBundleIsLive(): bool
    {
        $draft = $this->shopDraft();

        return $draft->bundleOffer?->isTrackedAt($draft->price) === true;
    }

    /**
     * The draft behind the previewed snapshot. Reading the snapshot lives in
     * {@see ShopDraft} so an MCP tool builds the same thing; this only adds
     * the form state around it.
     */
    protected function shopDraft(): ShopDraft
    {
        $manual = $this->adapterKey === 'user-selector';

        // Livewire rehydrates public properties from JSON, so narrow the key
        // type rather than trusting what came back over the wire.
        $snapshot = [];

        foreach ($this->snapshot ?? [] as $key => $value) {
            if (is_string($key)) {
                $snapshot[$key] = $value;
            }
        }

        return ShopDraft::fromSnapshot(
            snapshot: $snapshot,
            url: (string) $this->normalizedUrl,
            adapterKey: (string) $this->adapterKey,
            priceSelector: $manual ? trim($this->priceSelector) : null,
            titleSelector: $manual ? (trim($this->titleSelector) ?: null) : null,
            imageSelector: $manual ? (trim($this->imageSelector) ?: null) : null,
            variantKey: $this->chosenVariantKey,
        );
    }

    /**
     * @param  array<array-key, mixed>  $selectors
     */
    private static function selector(array $selectors, string $key): ?string
    {
        $value = $selectors[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function showPreview(ProbeOutcome $outcome): void
    {
        $snapshot = $outcome->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        $this->state = 'preview';
        $this->snapshot = ShopDraft::flatten($outcome);
        $this->imageUrls = ShopDraft::imageUrls($outcome);
        $this->normalizedUrl = $outcome->normalizedUrl;
        $this->host = $outcome->host;
        $this->adapterKey = $outcome->adapterKey;
        $this->variantPicked = $outcome->pickedVariantKey !== null;

        if ($outcome->pickedVariantKey !== null) {
            $this->chosenVariantKey = $outcome->pickedVariantKey;
            $this->variants = self::variantRows($outcome);
        }

        $this->onPreviewShown($outcome);
    }

    private function handleFailure(ProbeOutcome $outcome): void
    {
        if ($outcome->shouldOfferManualSelector()) {
            $this->errorCode = $outcome->extractionReason;
            $this->errorContext = $outcome->context;
            $this->state = 'manual_selector';

            return;
        }

        assert($outcome->errorCode !== null);

        // The adapter's reason rides along, so the message can name a cause
        // the shop itself is behind rather than a page DipCatch cannot read.
        $context = $outcome->extractionReason === null
            ? $outcome->context
            : [...$outcome->context ?? [], 'reason' => $outcome->extractionReason];

        $this->failWith($outcome->errorCode->value, $context);
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function failWith(string $code, ?array $context): void
    {
        $this->state = 'error';
        $this->errorCode = $code;
        $this->errorContext = $context;
    }

    private function resetPreview(): void
    {
        $this->snapshot = null;
        $this->imageUrls = [];
        $this->normalizedUrl = null;
        $this->host = null;
        $this->adapterKey = null;
        $this->errorCode = null;
        $this->errorContext = null;
        $this->onProbeReset();
    }

    private function resetProbeState(): void
    {
        $this->reset([
            'url',
            'state',
            'snapshot',
            'imageUrls',
            'normalizedUrl',
            'host',
            'adapterKey',
            'errorCode',
            'errorContext',
            'priceSelector',
            'titleSelector',
            'imageSelector',
            'variants',
            'chosenVariantKey',
            'variantPicked',
        ]);
        $this->state = 'idle';
        $this->manualCurrency = $this->defaultManualCurrency();
        $this->onProbeReset();
    }
}
