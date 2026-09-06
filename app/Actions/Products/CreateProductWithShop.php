<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\ShopDraft;
use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a product and its first shop together, the way the URL-first flow
 * does. One transaction, so a plan limit hit part-way leaves nothing behind.
 */
final readonly class CreateProductWithShop
{
    public function __construct(
        private PlanLimits $limits,
        private AttachShop $attachShop,
    ) {}

    /**
     * @throws PlanLimitReached
     */
    public function __invoke(User $actor, ProductDraft $product, ShopDraft $shop): Product
    {
        return DB::transaction(function () use ($actor, $product, $shop): Product {
            $this->limits->guardProduct($actor);

            $created = Product::query()->create([
                'user_id' => $actor->getKey(),
                'title' => $product->title,
                'image_url' => $product->imageUrl,
                'currency' => $shop->currency,
                'drop_threshold_pct' => $product->dropThresholdPct,
                'drop_threshold_abs' => $product->dropThresholdAbs,
                'active' => true,
            ]);

            $this->attachShop->firstShopOf($created, $shop);

            return $created;
        });
    }
}
