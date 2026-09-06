<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Writes one shop onto a product, with its first price check.
 *
 * The single place this happens, so the web form and an MCP tool cannot drift
 * on the plan guard, the scrape-status columns, or the recompute.
 */
final readonly class AttachShop
{
    public function __construct(private PlanLimits $limits) {}

    /**
     * @throws PlanLimitReached
     */
    public function __invoke(Product $product, ShopDraft $draft): Shop
    {
        return DB::transaction(function () use ($product, $draft): Shop {
            // Guard first: the limit has to bite before a row exists, and the
            // guard locks the owner row so two racing requests serialise.
            $this->limits->guardShop($product);

            $shop = $this->write($product, $draft);

            $check = PriceCheck::create([
                'shop_id' => $shop->id,
                'price' => $draft->price,
                'currency' => $draft->currency,
                'in_stock' => $draft->inStock,
                'status' => ScrapeStatus::Ok->value,
                'checked_at' => now(),
            ]);

            // With the triggering id, not bare: drop detection reads it.
            $product->recomputeCheapestShop((int) $check->id);

            return $shop;
        });
    }

    /**
     * The same write with no plan guard, for the first shop of a product that
     * is being created in the same transaction — `guardProduct` has already
     * run, and a product with no shops cannot be over the shop limit.
     */
    public function firstShopOf(Product $product, ShopDraft $draft): Shop
    {
        $shop = $this->write($product, $draft);

        $check = PriceCheck::create([
            'shop_id' => $shop->id,
            'price' => $draft->price,
            'currency' => $draft->currency,
            'in_stock' => $draft->inStock,
            'status' => ScrapeStatus::Ok->value,
            'checked_at' => now(),
        ]);

        $product->recomputeCheapestShop((int) $check->id);

        return $shop;
    }

    private function write(Product $product, ShopDraft $draft): Shop
    {
        return $product->shops()->create([
            'url' => $draft->url,
            'adapter_key' => $draft->adapterKey,
            'price_selector' => $draft->priceSelector,
            'title_selector' => $draft->titleSelector,
            'image_selector' => $draft->imageSelector,
            'image_url' => $draft->imageUrl,
            'gtin' => $draft->gtin,
            'variant_key' => $draft->variantKey,
            'pack_quantity' => $draft->packSize?->quantity,
            'pack_unit' => $draft->packSize?->unit,
            'currency' => $draft->currency,
            'initial_price' => $draft->price,
            'initial_checked_at' => now(),
            'current_price' => $draft->price,
            'current_in_stock' => $draft->inStock,
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'last_status' => ScrapeStatus::Ok->value,
        ]);
    }
}
