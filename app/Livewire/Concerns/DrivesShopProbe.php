<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Shops\ProbeOutcome;
use App\Actions\Shops\ProbeShopUrl;
use App\Models\Product;
use App\Models\User;
use App\PriceAdapters\ShopSnapshot;
use App\PriceAdapters\VariantCandidate;
use App\Support\ImageUrl;
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
        if ($this->chosenVariantKey === null || $this->chosenVariantKey === '') {
            return;
        }

        $this->runProbe($probe, variantKey: $this->chosenVariantKey);
    }

    public function showManualSelector(): void
    {
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

        $outcome = $probe($this->probeSubject(), $url, $actor, $selectors, $currency, $variantKey);

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

    protected function snapshotImageUrl(): ?string
    {
        $image = $this->snapshot['image_url'] ?? null;

        return is_string($image) && $image !== '' ? $image : null;
    }

    /**
     * Pack size behind the previewed snapshot: the transported structured
     * size, with the title fallback only for non-authoritative sources.
     */
    protected function snapshotPackSize(): ?PackSize
    {
        $snapshot = $this->snapshot ?? [];

        $packSize = $snapshot['pack_size'] ?? null;
        $title = $snapshot['title'] ?? null;

        return PackSize::resolve(
            is_string($packSize) ? $packSize : null,
            (bool) ($snapshot['pack_size_authoritative'] ?? false),
            is_string($title) ? $title : null,
        );
    }

    private function showPreview(ProbeOutcome $outcome): void
    {
        $snapshot = $outcome->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        $this->state = 'preview';
        $this->snapshot = [
            'title' => $snapshot->title,
            'image_url' => ImageUrl::absolute($snapshot->imageUrl, $outcome->normalizedUrl ?? ''),
            'price' => $snapshot->price,
            'currency' => $snapshot->currency,
            'in_stock' => $snapshot->inStock,
            'pack_size' => $snapshot->packSize,
            'pack_size_authoritative' => $snapshot->packSizeAuthoritative,
        ];
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
