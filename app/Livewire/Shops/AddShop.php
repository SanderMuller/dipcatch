<?php declare(strict_types=1);

namespace App\Livewire\Shops;

use App\Actions\Shops\ProbeOutcome;
use App\Actions\Shops\ProbeShopUrl;
use App\Enums\ProbeFailure;
use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\User;
use App\PriceAdapters\ShopSnapshot;
use App\PriceAdapters\VariantCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Add-shop state machine driving the "Add another shop" form.
 *
 * States: 'idle' | 'preview' | 'error' | 'manual_selector' | 'variant_chooser'
 *  - no_adapter_matched      → flips to manual_selector
 *  - multiple_variants found → flips to variant_chooser
 */
class AddShop extends Component
{
    public Product $product;

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

    public function mount(Product $product): void
    {
        $this->product = $product;
        $this->manualCurrency = $product->currency !== '' ? $product->currency : 'EUR';
    }

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

        $this->runProbe($probe, [], null, $this->chosenVariantKey);
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
            $this->failWith('empty_url', null);

            return;
        }

        /** @var User|null $actor */
        $actor = auth()->user();
        if (! $actor instanceof User) {
            $this->failWith('unauthenticated', null);

            return;
        }

        $outcome = $probe($this->product, $url, $actor, $selectors, $currency, $variantKey);

        match (true) {
            $outcome->isSuccess() => $this->showPreview($outcome),
            $outcome->isDuplicate() => $this->failWith('duplicate', [
                'existing_shop_host' => $outcome->existingShop?->host,
            ]),
            $outcome->isAmbiguous() => $this->showVariantChooser($outcome),
            default => $this->handleFailure($outcome),
        };
    }

    public function showManualSelector(): void
    {
        $this->state = 'manual_selector';
        $this->errorCode = null;
        $this->errorContext = null;
    }

    public function confirm(): void
    {
        if ($this->state !== 'preview' || $this->snapshot === null
            || $this->normalizedUrl === null || $this->host === null
            || $this->adapterKey === null) {
            return;
        }

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

        $offerId = DB::transaction(function () use (
            $snapshotPrice,
            $snapshotCurrency,
            $snapshotInStock,
            $priceSelector,
            $titleSelector,
            $imageSelector,
            $variantKey,
        ): string {
            $shop = $this->product->shops()->create([
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

            $this->product->recomputeCheapestShop((int) $check->id);

            return (string) $shop->id;
        });

        $this->dispatch('shop-added', offerId: $offerId);
        $this->resetAll();
    }

    public function cancel(): void
    {
        $this->resetAll();
    }

    public function render(): View
    {
        return view('livewire.shops.add-shop');
    }

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

    private function showPreview(ProbeOutcome $outcome): void
    {
        $snapshot = $outcome->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        $this->state = 'preview';
        $this->snapshot = [
            'title' => $snapshot->title,
            'image_url' => $snapshot->imageUrl,
            'price' => $snapshot->price,
            'currency' => $snapshot->currency,
            'in_stock' => $snapshot->inStock,
        ];
        $this->normalizedUrl = $outcome->normalizedUrl;
        $this->host = $outcome->host;
        $this->adapterKey = $outcome->adapterKey;
    }

    private function handleFailure(ProbeOutcome $outcome): void
    {
        // Auto-detect couldn't find the price OR a user-selector probe failed:
        // keep the URL the user pasted and stay in the manual-selector form so
        // they can adjust the selector without re-typing the URL. The Layer-1
        // reason rides on ProbeOutcome::$extractionReason whenever
        // errorCode === ProbeFailure::ExtractionFailed (see spec failure-code-enum).
        $reason = $outcome->extractionReason;
        if ($outcome->errorCode === ProbeFailure::ExtractionFailed
            && $reason !== null
            && ($reason === 'no_adapter_matched' || str_starts_with($reason, 'user_selector_'))) {
            $this->errorCode = $reason;
            $this->errorContext = $outcome->context;
            $this->state = 'manual_selector';

            return;
        }

        // Type-wise PHPStan proves errorCode is non-null here (handleFailure
        // is only routed to by the failed-state match arm in submitProbe, and
        // ProbeOutcome::failed() requires a non-null ProbeFailure). Defensive
        // null guard anyway — this is the UI boundary and a future malformed
        // outcome should surface as an inline 'unknown' error rather than a
        // production TypeError (assertions are dev-only).
        // @phpstan-ignore nullsafe.neverNull
        $code = $outcome->errorCode?->value ?? 'unknown';
        $this->failWith($code, $outcome->context);
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

    private function resetAll(): void
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
        $this->manualCurrency = $this->product->currency !== '' ? $this->product->currency : 'EUR';
    }
}
