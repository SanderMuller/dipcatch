<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Chain-of-responsibility over registered adapters. Per spec §2:
 *
 *   - skip      → try the next adapter.
 *   - failed    → stop the chain, return the failure as-is.
 *   - success   → stop the chain, return the snapshot.
 *
 * A persisted `adapter_key` (set from a prior successful check) is run first
 * as a hint. A generic key never short-circuits, so a host that later gained
 * a dedicated adapter picks it up. A host-specific hint that skips (the URL
 * moved to another host) falls through to the chain; one that fails or comes
 * back ambiguous ends resolution, because a weaker adapter would otherwise
 * read some other number off the page and call it this product's price.
 *
 * An optional {@see AdapterContext} carries user-supplied selectors and a
 * fallback currency; today only {@see UserSelectorAdapter} consumes it.
 */
final readonly class AdapterResolver
{
    /**
     * @param  list<ShopAdapter>  $adapters
     */
    public function __construct(
        private array $adapters,
    ) {}

    public function resolve(
        string $url,
        string $html,
        ?string $persistedKey = null,
        ?AdapterContext $context = null,
    ): ExtractionResult {
        $persisted = $persistedKey !== null ? $this->findByKey($persistedKey) : null;

        // Generic keys (jsonld etc.) never short-circuit — a host that
        // later gained a dedicated adapter must get it on the next check.
        if (! $persisted instanceof HostSpecificAdapter) {
            return $this->runChain($url, $html, skipKey: null, context: $context);
        }

        $result = $persisted->extract($url, $html, $context);

        // Only `skip` falls through: the adapter said the URL is not its
        // host. Anything else is that host's own verdict.
        //
        // Ambiguous means the page genuinely has multiple variants, and a
        // failure means the host's own extraction did not hold — in both
        // cases a weaker adapter would pick a number off the page and
        // present it as this product's price, which is how a wrong price
        // reaches a drop alert. Fail loudly instead.
        if (! $result->isSkip()) {
            return $result->withAdapterKey($persisted->key());
        }

        // The hint already ran and skipped — exclude it from the chain.
        return $this->runChain($url, $html, $persistedKey, $context);
    }

    private function runChain(string $url, string $html, ?string $skipKey, ?AdapterContext $context): ExtractionResult
    {
        foreach ($this->adapters as $adapter) {
            if ($skipKey !== null && $adapter->key() === $skipKey) {
                continue;
            }

            $result = $adapter->extract($url, $html, $context);

            if ($result->isSuccess() || $result->isFailed() || $result->isAmbiguous()) {
                return $result->withAdapterKey($adapter->key());
            }
        }

        return ExtractionResult::failed('no_adapter_matched');
    }

    private function findByKey(string $key): ?ShopAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->key() === $key) {
                return $adapter;
            }
        }

        return null;
    }
}
