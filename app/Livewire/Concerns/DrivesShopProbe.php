<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Shops\ProbeOutcome;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\ShopDraft;
use App\Models\Product;
use App\Models\User;
use App\PriceAdapters\ShopSnapshot;
use App\PriceAdapters\VariantCandidate;
use App\Support\PackSize;

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

    /**
     * The product the probe dedupes and currency-checks against, or null
     * in create mode (the probed currency then defines the product).
     */
    abstract protected function probeSubject(): ?Product;

    public function probe(ProbeShopUrl $probe): void
    {
        $this->runProbe($probe);
    }

    public function probeWithSelectors(ProbeShopUrl $probe): void
    {
        // Resolve the subject before the early return below: implementations
        // authorize the hydrated product here, and a public Livewire action
        // must not touch component state for a product the caller cannot see.
        $this->probeSubject();

        $price = trim($this->priceSelector);
        if ($price === '') {
            $this->errorCode = 'user_selector_required';
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
        $this->probeSubject();

        if ($this->chosenVariantKey === null || $this->chosenVariantKey === '') {
            return;
        }

        $this->runProbe($probe, variantKey: $this->chosenVariantKey);
    }

    public function showManualSelector(): void
    {
        $this->probeSubject();

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
        $this->resetPreview();
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
        $outcome = $probe($this->probeSubject(), $url, $actor, [
            'price' => self::selector($selectors, 'price'),
            'title' => self::selector($selectors, 'title'),
            'image' => self::selector($selectors, 'image'),
        ], $currency, $variantKey);

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

    private function showVariantChooser(ProbeOutcome $outcome): void
    {
        $this->state = 'variant_chooser';
        $this->normalizedUrl = $outcome->normalizedUrl;
        $this->host = $outcome->host;
        $this->variants = array_map(
            static fn (VariantCandidate $v): array => [
                'key' => $v->key,
                'title' => $v->title,
                'price' => $v->price,
                'currency' => $v->currency,
            ],
            $outcome->variants,
        );
        $this->chosenVariantKey ??= $this->variants[0]['key'] ?? null;
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
        $this->normalizedUrl = $outcome->normalizedUrl;
        $this->host = $outcome->host;
        $this->adapterKey = $outcome->adapterKey;

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
        $this->failWith($outcome->errorCode->value, $outcome->context);
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
        $this->normalizedUrl = null;
        $this->host = null;
        $this->adapterKey = null;
        $this->errorCode = null;
        $this->errorContext = null;
    }

    private function resetProbeState(): void
    {
        $this->reset([
            'url',
            'state',
            'snapshot',
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
        ]);
        $this->state = 'idle';
        $this->manualCurrency = ($this->probeSubject()?->currency ?: null) ?? 'EUR';
    }
}
