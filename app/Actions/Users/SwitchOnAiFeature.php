<?php declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\AiFeature;
use App\Jobs\CategoriseExistingProduct;
use App\Models\Product;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Services\ShopDiscovery\WebShopDiscovery;
use Illuminate\Support\Facades\Cache;

/**
 * Switches an AI feature on and starts its work now, rather than at the
 * nightly run.
 */
final readonly class SwitchOnAiFeature
{
    /**
     * Products searched at once when shop checks go on, newest first. Each
     * search is paid from the app-wide daily limit; the nightly run does the rest.
     */
    public const int DISCOVERIES_AT_SWITCH_ON = 10;

    public function __construct(private WebShopDiscovery $discovery) {}

    public function __invoke(User $user, AiFeature $feature): void
    {
        $wasOn = $feature->isOn($user);
        $user->forceFill([$feature->column() => true])->save();

        if (! $wasOn) {
            $this->startWork($user, $feature);
        }
    }

    /** The work for a feature that just went on, for a caller that saved the switch itself. */
    public function startWork(User $user, AiFeature $feature): int
    {
        return match ($feature) {
            AiFeature::Categories => CategoriseExistingProduct::queueFor($user),
            AiFeature::ShopChecks => $this->queueDiscovery($user),
        };
    }

    private function queueDiscovery(User $user): int
    {
        // Once a day per account: switching off and on again must not drain
        // the daily limit every account shares, ten products at a time.
        if (! Cache::add("ai-switch-on-discovery:{$user->id}", true, now()->addDay())) {
            return 0;
        }

        $products = Product::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            // Only products discovery searches, so the cap is not spent on ones it skips.
            ->where('currency', 'EUR')
            ->has('shops')
            ->whereNotIn('id', WebDiscovery::query()->select('product_id'))
            ->latest()
            ->limit(self::DISCOVERIES_AT_SWITCH_ON)
            ->with('shops')
            ->get();

        return $products->filter(fn (Product $product): bool => $this->discovery->queue($product->setRelation('user', $user)))->count();
    }
}
