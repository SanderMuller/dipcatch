<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Products\SuggestAlert;
use App\Enums\PromotionDepthBand;
use App\Models\Product;
use App\Services\TypeSafe\TypeSafeClient;
use App\Support\AlertSuggestion\AlertSuggestion;
use App\Support\AlertSuggestion\NormalPrice;
use Livewire\Attributes\Locked;

trait SuggestsAlert
{
    /** Jev's band for the suggested alert, while `$bandFingerprint` still describes the product. */
    #[Locked]
    public ?PromotionDepthBand $band = null;

    #[Locked]
    public ?string $bandFingerprint = null;

    /** The per-unit target "Use this alert" filled in, so saving can tell it was kept. */
    #[Locked]
    public ?string $chosenTarget = null;

    abstract protected function suggestedProduct(): Product;

    /** Reports what the last action did. */
    abstract protected function announce(string $message): void;

    /**
     * For an account with the AI shop check. Asked once per state of the
     * product; SuggestAlert caches the answer.
     */
    public function askJev(SuggestAlert $suggest): void
    {
        $product = $this->suggestedProduct();

        if ($product->user?->wantsShopChecks() !== true || $suggest->fingerprint($product) === $this->bandFingerprint) {
            return;
        }

        $this->band = $suggest->band($product);
        // From the product as it is after the ask: a shop that changed
        // meanwhile gets `Unknown` for its new state, not a checking card
        // that never asks again.
        $this->bandFingerprint = $suggest->fingerprint($product->refresh());
        $this->announce(__('Suggested alert updated.'));
    }

    protected function alertSuggestion(Product $product): AlertSuggestion
    {
        return AlertSuggestion::for($product, $this->knownBand($product) ?? PromotionDepthBand::Unknown);
    }

    /**
     * @return array{suggestion: AlertSuggestion, onOfferNow: bool, asksJev: bool, usesJev: bool, canSwitchOnAi: bool}
     */
    protected function suggestionCard(Product $product): array
    {
        $user = $product->user;
        $band = $this->knownBand($product);

        return [
            'suggestion' => AlertSuggestion::for($product, $band ?? PromotionDepthBand::Unknown),
            'onOfferNow' => $product->shops->contains(NormalPrice::isOnOffer(...)),
            'asksJev' => $band === null,
            'usesJev' => $user?->wantsShopChecks() === true,
            'canSwitchOnAi' => $user?->entitlements()->allowsShopChecks() === true && $user->wantsShopChecks() !== true,
        ];
    }

    /**
     * Jev's band for the product as it is now: from this page, or remembered
     * from an earlier ask. Null while Jev still has to be asked; `Unknown`
     * for an account without AI help.
     */
    private function knownBand(Product $product): ?PromotionDepthBand
    {
        if ($product->user?->wantsShopChecks() !== true || ! TypeSafeClient::configured()) {
            return PromotionDepthBand::Unknown;
        }

        $suggest = app(SuggestAlert::class);

        if ($this->band !== null && $this->bandFingerprint === $suggest->fingerprint($product)) {
            return $this->band;
        }

        return $suggest->remembered($product);
    }
}
