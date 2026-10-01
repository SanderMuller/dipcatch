<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Enums\WebFindingStatus;
use App\Models\Product;
use App\Models\WebSearch;
use App\Models\WebShopFinding;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Web findings from a Klarna lead's lookup. See specs/klarna-shop-leads.md
 * §5.2, `LookUpKlarnaLead` step 3.
 */
final class LeadFindings
{
    /**
     * The lookup's first results on the lead's shop, as new findings, or as
     * findings an earlier check turned away coming back. True when any was
     * added.
     *
     * @param  array{host: string, title: string, pack_quantity: ?float, pack_unit: ?string, state: string, attempts: int}  $entry
     */
    public static function store(Product $product, WebSearch $search, string $host, string $klarnaUrl, array $entry): bool
    {
        $fingerprint = WebShopFinding::fingerprintFor($product);
        $added = false;
        $taken = 0;

        foreach ($search->results as $result) {
            if ($taken >= Config::integer('dipcatch.web_discovery.lead_results_per_host')) {
                break;
            }

            try {
                $url = UrlNormalizer::normalize($result['link']);
            } catch (InvalidArgumentException) {
                continue;
            }

            $resultHost = UrlNormalizer::normalizeHost((string) parse_url($url, PHP_URL_HOST));

            if ($resultHost !== $host && ! str_ends_with($resultHost, ".{$host}")) {
                continue;
            }

            $taken++;
            $lead = [
                'lead_url' => $klarnaUrl,
                'lead_pack_quantity' => $entry['pack_quantity'],
                'lead_pack_unit' => $entry['pack_unit'],
                'web_search_id' => $search->id,
                'search_title' => mb_substr($result['title'], 0, 255),
                'snippet' => $result['snippet'],
            ];
            $hash = UrlNormalizer::hash($url);
            $existing = WebShopFinding::query()->where('product_id', $product->id)->where('url_hash', $hash)->first();

            if (! $existing instanceof WebShopFinding) {
                $added = WebShopFinding::query()->insertOrIgnore([[
                    'product_id' => $product->id,
                    'url' => $result['link'],
                    'url_hash' => $hash,
                    // The lead's shop, also for a page on a subdomain, so the
                    // first check reads one page per shop.
                    'host' => $host,
                    'status' => WebFindingStatus::New->value,
                    'fingerprint' => $fingerprint,
                    ...$lead,
                ]]) > 0 || $added;

                continue;
            }

            if ($existing->dismissed_at === null && in_array($existing->status, [WebFindingStatus::Unreadable, WebFindingStatus::Declined, WebFindingStatus::Rejected], strict: true)) {
                $added = $existing->writeIfUnchanged($existing->status, [
                    ...$lead,
                    ...WebShopFinding::CLEARED_CHECKS,
                    'status' => WebFindingStatus::New,
                    'fingerprint' => $fingerprint,
                    'generation' => $existing->generation + 1,
                ]) > 0 || $added;
            }
        }

        return $added;
    }

    /**
     * A lead finding checked against an old fingerprint or barcode starts
     * over with its lead kept: its search title and snippet come from its
     * own lookup, not the open search.
     */
    public static function startOverIf(bool $stale, WebShopFinding $finding, string $fingerprint): void
    {
        if (! $stale) {
            return;
        }

        WebShopFinding::query()->whereKey($finding->id)->update([
            ...WebShopFinding::CLEARED_CHECKS,
            'status' => WebFindingStatus::New,
            'fingerprint' => $fingerprint,
            'generation' => $finding->generation + 1,
        ]);
    }
}
