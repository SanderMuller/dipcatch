<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Enums\WebFindingStatus;
use App\Models\Product;
use App\Models\WebDiscovery;
use App\Models\WebShopFinding;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Support\PackSize;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * The second Jev check of web shop discovery, with what the read page says
 * beside the search result's title. See specs/web-shop-discovery.md §5.2.
 */
final readonly class WebSecondCheck
{
    /** Longer than a check job may run (`CheckWebFindings` timeout, and the queue's `retry_after`). */
    public const int CLAIM_SECONDS = 120;

    public function __construct(private ShopMatchCheck $shopMatch) {}

    /**
     * Checks the read findings this call claims. A finding the answer leaves
     * out goes back to `read` for the next run.
     */
    public function check(Product $product): void
    {
        $claimed = $this->claim($product);

        try {
            if ($claimed !== []) {
                $this->judge($product, $claimed);
            }
        } catch (Throwable $e) {
            foreach ($claimed as $finding) {
                $finding->writeIfUnchanged(WebFindingStatus::Checking, ['status' => WebFindingStatus::Read, 'claimed_at' => null]);
            }

            throw $e;
        }

        WebDiscovery::finishIfDone($product);
    }

    /**
     * Puts back findings a second check claimed and never finished. Only
     * claims older than any live check can be, so a check still running keeps
     * its own; a check killed at its timeout is put back by the next run.
     */
    public static function releaseStaleClaims(Product $product): void
    {
        WebShopFinding::query()
            ->where('product_id', $product->id)
            ->where('status', WebFindingStatus::Checking)
            ->where('claimed_at', '<=', now()->subSeconds(self::CLAIM_SECONDS))
            ->update(['status' => WebFindingStatus::Read, 'claimed_at' => null]);
    }

    /**
     * One row at a time, so two jobs never send the same finding.
     *
     * @return array<string, WebShopFinding>
     */
    private function claim(Product $product): array
    {
        $claimed = [];

        foreach (WebShopFinding::query()->where('product_id', $product->id)->current($product)->where('status', WebFindingStatus::Read)->get() as $finding) {
            if ($finding->writeIfUnchanged(WebFindingStatus::Read, ['status' => WebFindingStatus::Checking, 'claimed_at' => now()]) === 1) {
                $claimed["f{$finding->id}"] = $finding;
            }
        }

        return $claimed;
    }

    /**
     * @param  array<string, WebShopFinding>  $claimed
     */
    private function judge(Product $product, array $claimed): void
    {
        $candidates = array_map(static fn (WebShopFinding $finding): array => ShopMatchCheck::candidate(
            shop: $finding->served_host ?? $finding->host,
            title: $finding->page_title,
            packSize: self::packLabel($finding),
            price: $finding->page_price === null ? null : "{$finding->page_price} {$finding->page_currency}",
            gtin: $finding->page_gtin,
            listingTitle: $finding->search_title,
        ), $claimed);

        $anyPack = array_keys(array_filter($claimed, static fn (WebShopFinding $finding): bool => $finding->isLead()));
        $answers = $this->shopMatch->ask($product, ShopCheckPurpose::WebDiscoveryConfirm, $candidates, quick: false, anyPackKeys: $anyPack, shoppersCountry: ShoppersCountry::name(ShoppersCountry::forProduct($product)));
        $gtins = WebShopFinding::trackedGtins($product);

        foreach ($claimed as $key => $finding) {
            $chance = $answers[$key] ?? null;

            $proposed = $chance !== null && $chance >= Config::float('dipcatch.shop_checks.accept_from');

            $finding->writeIfUnchanged(WebFindingStatus::Checking, $chance === null
                ? ['status' => WebFindingStatus::Read, 'claimed_at' => null]
                : [
                    'claimed_at' => null,
                    'status' => $proposed ? WebFindingStatus::Proposed : WebFindingStatus::Declined,
                    'second_chance' => $chance,
                    'matched_gtin' => $proposed && in_array($finding->page_gtin, $gtins, strict: true) ? $finding->page_gtin : null,
                    'checked_gtins' => $gtins,
                    'checked_at' => now(),
                ]);
        }
    }

    private static function packLabel(WebShopFinding $finding): ?string
    {
        $size = $finding->page_pack_quantity === null || $finding->page_pack_unit === null
            ? null
            : PackSize::of((float) $finding->page_pack_quantity, $finding->page_pack_unit);

        return $size === null ? null : ShopMatchCheck::packLabel($size);
    }
}
