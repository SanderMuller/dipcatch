<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\MicrodataAdapter;
use App\PriceAdapters\OpenGraphAdapter;
use App\PriceAdapters\OwnsHosts;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopifyAdapter;

/**
 * A shop whose product pages state their price in structured data, marketed
 * by name on a landing page. Owning the host is what lets the site say
 * DipCatch reads it; the reading itself is the generic structured-data chain.
 *
 * Runs a fixed copy of the generic chain, in its configured order (JSON-LD,
 * Shopify, microdata, OpenGraph) with {@see AdapterResolver}'s rules: only a
 * `skip` moves on, and a failure or a variant question is the answer. The
 * heuristic GenericAdapter is left out on purpose: an owned host must not fall
 * to a reader that prices whatever number it finds.
 */
abstract readonly class StructuredDataHostAdapter implements HostSpecificAdapter, OwnsHosts, ShopAdapter
{
    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matchesAny($url, $this->ownedHosts())) {
            return ExtractionResult::skip();
        }

        foreach ([new JsonLdAdapter(), new ShopifyAdapter(), new MicrodataAdapter(), new OpenGraphAdapter()] as $adapter) {
            $result = $adapter->extract($url, $html, $context);

            if (! $result->isSkip()) {
                return $result;
            }
        }

        return ExtractionResult::failed($this->key() . '_extraction_failed');
    }
}
