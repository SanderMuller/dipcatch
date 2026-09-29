<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

use App\Actions\Shops\ShopDraft;
use App\Models\Product;
use App\Models\Shop;
use App\Support\PackSize;
use Illuminate\Support\Facades\Log;

/**
 * Asks Jev whether a shop sells the same product, in the same pack, as the
 * shops a product already tracks. Only for an account that switched the
 * check on and whose plan allows it, and never a reason to refuse a shop:
 * the answer is a warning the person can overrule.
 */
final readonly class ShopMatchCheck
{
    public function __construct(
        private TypeSafeClient $client,
        private CategorisationBudget $budget,
    ) {}

    /**
     * The chance the drafted shop sells the same product and pack, or null
     * when nothing was checked: the account has not opted in, the product has
     * no shop to compare with, the budget is spent, or the call failed.
     */
    public function draft(Product $product, ShopDraft $draft): ?float
    {
        if (! $this->applies($product)) {
            return null;
        }

        // A shared barcode settles it without a paid call.
        if (self::sharesBarcode($product, $draft->gtin)) {
            return 1.0;
        }

        return $this->ask($product, ShopCheckPurpose::AddShop, ['draft' => self::candidate(
            shop: (string) parse_url($draft->url, PHP_URL_HOST),
            title: $draft->title,
            packSize: $draft->packSize === null ? null : self::packLabel($draft->packSize),
            price: "{$draft->trackedPrice()} {$draft->currency}",
            gtin: $draft->gtin,
        )])['draft'] ?? null;
    }

    public function applies(Product $product): bool
    {
        if (! TypeSafeClient::configured() || $product->user?->wantsShopChecks() !== true) {
            return false;
        }

        $product->loadMissing('shops');

        return $product->shops->isNotEmpty();
    }

    /**
     * @param  array<string, array<string, string>>  $candidates
     * @return array<string, float>
     */
    public function ask(Product $product, ShopCheckPurpose $purpose, array $candidates): array
    {
        $user = $product->user;

        if ($user === null || ! $this->budget->allowsShopCheck($user, $purpose)) {
            Log::info('Same-product check skipped: the daily budget is spent.', ['product_id' => $product->id]);

            return [];
        }

        try {
            return $this->client->sameProduct($product, $candidates);
        } catch (TypeSafeRequestFailed $e) {
            Log::warning('Same-product check failed; the shop goes unchecked.', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    public static function candidate(string $shop, ?string $title, ?string $packSize, ?string $price, ?string $gtin = null): array
    {
        $fields = [];

        foreach (['shop' => $shop, 'title' => $title, 'pack_size' => $packSize, 'price' => $price, 'gtin' => $gtin] as $field => $value) {
            if ($value !== null && $value !== '') {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    private static function packLabel(PackSize $packSize): string
    {
        return rtrim(rtrim(number_format($packSize->quantity, 2, '.', ''), '0'), '.') . " {$packSize->unit}";
    }

    /** Whether an existing shop of the product carries this barcode. */
    private static function sharesBarcode(Product $product, ?string $gtin): bool
    {
        return is_string($gtin) && $gtin !== '' && $product->shops->contains(static fn (Shop $shop): bool => $shop->gtin === $gtin);
    }
}
