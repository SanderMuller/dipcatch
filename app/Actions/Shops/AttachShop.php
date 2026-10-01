<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Actions\Products\BorrowShopImage;
use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Enums\ScrapeStatus;
use App\Jobs\LookUpBolOffers;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Services\ShopDiscovery\WebShopDiscovery;
use Illuminate\Support\Facades\DB;

/**
 * Writes one shop onto a product, with its first price check.
 *
 * The single place this happens, so the web form and an MCP tool cannot drift
 * on the plan guard, the scrape-status columns, or the recompute.
 */
final readonly class AttachShop
{
    public function __construct(
        private PlanLimits $limits,
        private BorrowShopImage $borrowImage,
        private WebShopDiscovery $webShops,
    ) {}

    /**
     * @throws PlanLimitReached
     */
    public function __invoke(Product $product, ShopDraft $draft): Shop
    {
        $shop = DB::transaction(function () use ($product, $draft): Shop {
            // Guard first: the limit has to bite before a row exists, and the
            // guard locks the owner row so two racing requests serialise.
            $this->limits->guardShop($product);

            return $this->record($product, $draft);
        });

        // A shop in a new pack size hides the web suggestions until they are
        // checked against it.
        $this->webShops->requeueIfStale($product);
        // A new shop can bring the barcode bol.com is found by.
        LookUpBolOffers::dispatchFor($product);

        return $shop;
    }

    /**
     * The same write with no plan guard, for the first shop of a product that
     * is being created in the same transaction — `guardProduct` has already
     * run, and a product with no shops cannot be over the shop limit.
     */
    public function firstShopOf(Product $product, ShopDraft $draft): Shop
    {
        return $this->record($product, $draft);
    }

    /**
     * The shop row, its first price check, and the recompute that reads it.
     */
    private function record(Product $product, ShopDraft $draft): Shop
    {
        $shop = $this->write($product, $draft);
        ($this->borrowImage)($product, $shop);
        $trackedPrice = $draft->trackedPrice();
        $appliedBundle = $draft->bundleOffer?->isTrackedAt($trackedPrice) === true
            ? $draft->bundleOffer
            : null;

        $check = PriceCheck::create([
            'shop_id' => $shop->id,
            'price' => $trackedPrice,
            'single_item_price' => $draft->singleItemPrice ?? $draft->price,
            'bundle_quantity' => $appliedBundle?->quantity,
            'bundle_total_price' => $appliedBundle?->totalPrice,
            'currency' => $draft->currency,
            'in_stock' => $draft->inStock,
            'status' => ScrapeStatus::Ok->value,
            'checked_at' => now(),
            'claimed_regular_price' => $draft->claimRead ? $draft->keptClaim() : null,
            'seller' => $draft->seller,
            'claim_read' => $draft->claimRead,
            'shelf_inherited' => false,
            'consumer_price_issue' => $draft->consumerPriceIssue,
        ]);

        // With the triggering id, not bare: drop detection reads it.
        $product->recomputeCheapestShop((int) $check->id);

        return $shop;
    }

    private function write(Product $product, ShopDraft $draft): Shop
    {
        $trackedPrice = $draft->trackedPrice();

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
            'initial_price' => $trackedPrice,
            'initial_checked_at' => now(),
            'current_price' => $trackedPrice,
            'single_item_price' => $draft->singleItemPrice ?? $draft->price,
            'bundle_quantity' => $draft->bundleOffer?->quantity,
            'bundle_total_price' => $draft->bundleOffer?->totalPrice,
            'promotion_starts_at' => $draft->promotionWindow?->startsAt?->utc(),
            'promotion_ends_at' => $draft->promotionWindow?->endsAt->utc(),
            'promotion_label' => $draft->promotionWindow?->label,
            'current_in_stock' => $draft->inStock,
            'consumer_price_issue' => $draft->consumerPriceIssue,
            'consumer_price_note' => $draft->consumerPriceNote,
            'claimed_regular_price' => $draft->claimRead ? $draft->keptClaim() : null,
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'last_status' => ScrapeStatus::Ok->value,
        ]);
    }
}
