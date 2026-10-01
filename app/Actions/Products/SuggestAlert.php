<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Enums\PromotionDepthBand;
use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\TypeSafeClient;
use App\Services\TypeSafe\TypeSafeRequestFailed;
use App\Support\AlertSuggestion\AlertSuggestion;

/**
 * The suggested alert for a product, with Jev's view of how deep its
 * promotions go when the owner has the AI shop check on. Without an answer
 * the suggestion stands on the category table and the shops alone.
 */
final readonly class SuggestAlert
{
    /** Below this, Jev's top band is a guess, and the category decides. */
    private const float MIN_PROBABILITY = 0.5;

    public function __construct(
        private TypeSafeClient $client,
        private CategorisationBudget $budget,
    ) {}

    public function __invoke(Product $product): AlertSuggestion
    {
        return AlertSuggestion::for($product, $this->band($product));
    }

    /** `Unknown` whenever there is no confident answer to give. */
    public function band(Product $product): PromotionDepthBand
    {
        $user = $product->user;

        if (! $user instanceof User || ! $user->wantsShopChecks() || ! TypeSafeClient::configured()
            || ! $this->budget->allowsShopCheck($user, ShopCheckPurpose::AlertSuggestion)) {
            return PromotionDepthBand::Unknown;
        }

        $state = $this->client->promotionDepthState($product);

        try {
            $chances = $this->client->promotionDepth($state);
        } catch (TypeSafeRequestFailed) {
            return PromotionDepthBand::Unknown;
        }

        // The state changed while Jev answered: the answer describes another product.
        if ($this->client->promotionDepthState($product->refresh()) !== $state) {
            return PromotionDepthBand::Unknown;
        }

        arsort($chances);
        $top = array_key_first($chances);

        return $top !== null && $chances[$top] >= self::MIN_PROBABILITY
            ? PromotionDepthBand::tryFrom($top) ?? PromotionDepthBand::Unknown
            : PromotionDepthBand::Unknown;
    }

    /**
     * A band asked for earlier, while it still applies: the product is the
     * same, and its owner still has AI help on.
     */
    public function stillApplies(Product $product, ?PromotionDepthBand $band, ?string $fingerprint): PromotionDepthBand
    {
        return $band !== null && $product->user?->wantsShopChecks() === true && $fingerprint === $this->fingerprint($product)
            ? $band
            : PromotionDepthBand::Unknown;
    }

    public function fingerprint(Product $product): string
    {
        return hash('xxh128', (string) json_encode($this->client->promotionDepthState($product)));
    }
}
