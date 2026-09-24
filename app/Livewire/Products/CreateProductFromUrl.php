<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Actions\Products\CreateProductWithShop;
use App\Actions\Products\ProductDraft;
use App\Actions\Shops\ProbeOutcome;
use App\Billing\PlanLimitReached;
use App\Livewire\Concerns\DrivesShopProbe;
use App\Models\Shop;
use App\Models\User;
use App\Services\Drops\ReferenceValue;
use App\Services\Drops\TierDefaults;
use App\Support\UnitTargetGuide;
use App\Support\UrlNormalizer;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\View\View;
use Laravel\Passport\Token;
use Livewire\Component;
use RuntimeException;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
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
final class CreateProductFromUrl extends Component
{
    use DrivesShopProbe;
    use HasFluentValidation;

    public string $title = '';

    public string $imageUrl = '';

    public string $thresholdPct = '';

    public string $thresholdAbs = '';

    public string $unitPriceTarget = '';

    /** @var array{id: string, title: string}|null Another product of this user already tracking the pasted URL. */
    public ?array $existingTrackedProduct = null;

    /** Create mode has no product yet: the probed currency defines one. */
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
            // Optional: an empty threshold stores nothing, and the drop check
            // uses the tier default for the price at the time.
            'thresholdPct' => FluentRule::numeric('Alert me when it drops by (%)')
                ->nullable()
                ->between(0.01, 99.98999999999999),
            'thresholdAbs' => FluentRule::numeric('Alert me when it drops by (amount)')->nullable()->min(0.01),
            'unitPriceTarget' => FluentRule::numeric('Target price per kilo, litre or piece')->nullable()->min(0.0001),
        ];
    }

    /**
     * The probed pack, for the unit-target component. Empty when the page
     * states no pack size: there is nothing to turn a pack price into a unit
     * price with.
     *
     * @return list<array{shopId: string, host: string, pack: string, perPack: float, price: ?float, unitPrice: ?float, bestValue: bool}>
     */
    public function unitTargetPacks(): array
    {
        $draft = $this->shopDraft();

        if ($draft->packSize === null || $this->host === null) {
            return [];
        }

        return [UnitTargetGuide::packChoice('new', $this->host, $draft->packSize, $draft->trackedPrice(), bestValue: true)];
    }

    /**
     * The thresholds the drop check will use when a field is left empty,
     * shown as the fields' placeholders. A new product's reference is its
     * first price: per unit when the page states a pack size, with the pack
     * price beside it.
     *
     * @return array{pct: string, abs: string}
     */
    public function suggestedThresholds(): array
    {
        $draft = $this->shopDraft();
        $price = $draft->trackedPrice();
        $unitPrice = $draft->packSize?->unitPriceFor($price);
        $defaults = TierDefaults::forReference(new ReferenceValue(
            value: $unitPrice ?? $price,
            kind: ReferenceValue::KIND_INITIAL,
            sampleSize: 0,
            unit: $unitPrice === null ? null : $draft->packSize?->unit,
            packValue: $unitPrice === null ? null : $price,
        ));

        return [
            'pct' => number_format($defaults['pct'], 2, '.', ''),
            'abs' => number_format($defaults['abs'] ?? TierDefaults::for($price)['abs'], 2, '.', ''),
        ];
    }

    protected function onPreviewShown(ProbeOutcome $outcome): void
    {
        $snapshot = $this->snapshot ?? [];

        $title = $snapshot['title'] ?? '';
        $this->title = is_string($title) ? $title : '';

        $image = $snapshot['image_url'] ?? '';
        $this->imageUrl = is_string($image) ? $image : '';

        $this->reset(['thresholdPct', 'thresholdAbs', 'unitPriceTarget']);

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
            dropThresholdPct: trim($this->thresholdPct) !== '' ? trim($this->thresholdPct) : null,
            dropThresholdAbs: trim($this->thresholdAbs) !== '' ? trim($this->thresholdAbs) : null,
            unitPriceTarget: trim($this->unitPriceTarget) !== '' ? trim($this->unitPriceTarget) : null,
        );

        try {
            $product = app(CreateProductWithShop::class)($this->currentUser(), $draft, $shop);
        } catch (PlanLimitReached $e) {
            Notification::make()
                ->warning()
                ->title('You have used all your free products')
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

    /**
     * The name of an assistant that can use the DipCatch tools on this
     * account now — a live grant with the MCP scope — so the hint says "ask
     * it" rather than "connect it". Null when none can.
     */
    private function connectedAssistant(): ?string
    {
        $token = Token::query()
            ->with('client')
            ->where('user_id', auth()->id())
            ->where('revoked', false)
            ->where(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('client', fn (EloquentQueryBuilder $client): EloquentQueryBuilder => $client->where('revoked', false))
            ->latest('created_at')
            ->get()
            ->first(fn (Token $token): bool => $token->can('mcp:use'));

        if (! $token instanceof Token) {
            return null;
        }

        return is_string($token->client?->name) && $token->client->name !== '' ? $token->client->name : __('Your assistant');
    }

    public function render(): View
    {
        return view('livewire.products.create-product-from-url', [
            // Only before a lookup: once a preview is on screen, the hint is noise.
            'connectedAssistant' => in_array($this->state, ['idle', 'error'], strict: true) ? $this->connectedAssistant() : null,
        ]);
    }
}
