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
use Illuminate\Support\Facades\Cache;

/**
 * The suggested alert for a product, with Jev's view of how deep its
 * promotions go when the owner has the AI shop check on. Without an answer
 * the suggestion stands on the category table and the shops alone.
 */
final readonly class SuggestAlert
{
    /** Below this, Jev's top band is a guess, and the category decides. */
    private const float MIN_PROBABILITY = 0.5;

    private const int REMEMBER_DAYS = 30;

    public function __construct(
        private TypeSafeClient $client,
        private CategorisationBudget $budget,
    ) {}

    public function __invoke(Product $product): AlertSuggestion
    {
        return AlertSuggestion::for($product, $this->band($product));
    }

    /**
     * `Unknown` whenever there is no confident answer to give. Jev's answer is
     * cached per state of the product.
     */
    public function band(Product $product): PromotionDepthBand
    {
        $user = $product->user;

        if (! $user instanceof User || ! $user->wantsShopChecks() || ! TypeSafeClient::configured()) {
            return PromotionDepthBand::Unknown;
        }

        // Before the budget, which counts a check when it is asked.
        $remembered = $this->remembered($product);

        if ($remembered instanceof PromotionDepthBand) {
            return $remembered;
        }

        if (! $this->budget->allowsShopCheck($user, ShopCheckPurpose::AlertSuggestion)) {
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

        if ($chances === []) {
            return PromotionDepthBand::Unknown;
        }

        arsort($chances);
        $top = array_key_first($chances);
        $band = $chances[$top] >= self::MIN_PROBABILITY
            ? PromotionDepthBand::tryFrom($top) ?? PromotionDepthBand::Unknown
            : PromotionDepthBand::Unknown;

        Cache::put($this->cacheKey($product), $band->value, now()->addDays(self::REMEMBER_DAYS));

        return $band;
    }

    /** Jev's earlier answer for the product as it is now, without asking. */
    public function remembered(Product $product): ?PromotionDepthBand
    {
        $value = Cache::get($this->cacheKey($product));

        return is_string($value) ? PromotionDepthBand::tryFrom($value) : null;
    }

    public function fingerprint(Product $product): string
    {
        return hash('xxh128', (string) json_encode($this->client->promotionDepthState($product)));
    }

    private function cacheKey(Product $product): string
    {
        return "alert-suggestion:band:{$product->id}:{$this->fingerprint($product)}";
    }
}
