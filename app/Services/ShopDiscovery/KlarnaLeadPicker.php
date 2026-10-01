<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Enums\WebFindingStatus;
use App\Models\HiddenShop;
use App\Models\Product;
use App\Models\Shop;
use App\Models\WebShopFinding;
use App\Services\ShopFetcher\UrlSafetyGuard;
use App\Support\NotAShop;
use App\Support\PackSize;
use App\Support\UnservableShops;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Which of a Klarna page's shops are worth a lookup. See
 * specs/klarna-shop-leads.md §5.2, step 2 and 3.
 */
final readonly class KlarnaLeadPicker
{
    public function __construct(private UrlSafetyGuard $guard) {}

    /**
     * The leads to look up: in the product's currency, at a shop the product
     * does not track or hide and that is not already being checked, with a
     * host that resolves and a size in a unit the product compares in. One
     * per shop, the tracked size first, and the best few of those.
     *
     * @param  list<ShopLead>  $leads
     * @return list<ShopLead>
     */
    public function pick(Product $product, array $leads): array
    {
        $product->loadMissing(['shops', 'user']);
        $tracked = $product->shops->map(static fn (Shop $shop): ?PackSize => $shop->packSize())->filter()->values()->all();
        $trackedHosts = $product->shops->pluck('host')->all();
        $hidden = HiddenShop::hostsOf($product->user);
        $unsupported = Config::array('site.unsupported_hosts');
        $busy = WebShopFinding::query()
            ->where('product_id', $product->id)
            ->whereIn('status', [WebFindingStatus::New, WebFindingStatus::PendingRead, WebFindingStatus::Read, WebFindingStatus::Checking, WebFindingStatus::Proposed])
            ->pluck('host')
            ->all();

        $kept = array_filter($leads, static function (ShopLead $lead) use ($product, $tracked, $trackedHosts, $hidden, $unsupported, $busy): bool {
            $unitFits = ! $lead->packSize instanceof PackSize || $tracked === [] || array_any($tracked, static fn (PackSize $size): bool => $size->unit === $lead->packSize->unit);

            return strcasecmp($lead->currency, $product->currency) === 0
                && $unitFits
                && ! in_array($lead->host, $trackedHosts, strict: true)
                && ! NotAShop::covers($lead->host)
                && ! HiddenShop::covers($hidden, $lead->host)
                && ! in_array($lead->host, $unsupported, strict: true)
                && UnservableShops::reasonFor($lead->host) === null
                && ! in_array($lead->host, $busy, strict: true);
        });

        $rank = static fn (ShopLead $lead): array => [
            $lead->packSize instanceof PackSize && array_any($tracked, static fn (PackSize $size): bool => $size->isSameSizeAs($lead->packSize)) ? 0 : ($lead->packSize instanceof PackSize ? 2 : 1),
            $lead->packSize instanceof PackSize ? (float) $lead->packSize->unitPriceFor($lead->price) : PHP_FLOAT_MAX,
            (float) $lead->price,
        ];

        usort($kept, static fn (ShopLead $a, ShopLead $b): int => $rank($a) <=> $rank($b));

        $perHost = [];

        foreach ($kept as $lead) {
            $perHost[$lead->host] ??= $lead;
        }

        // One DNS lookup per shop, not per offer.
        $resolving = array_filter(array_values($perHost), fn (ShopLead $lead): bool => $this->resolves($lead->host));

        return array_slice(array_values($resolving), 0, Config::integer('dipcatch.web_discovery.klarna_leads_per_product'));
    }

    /** Whether a lead's host resolves, so no search is spent on `joybuy.dnu`. */
    private function resolves(string $host): bool
    {
        try {
            $this->guard->assertSafe("https://{$host}/");

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
