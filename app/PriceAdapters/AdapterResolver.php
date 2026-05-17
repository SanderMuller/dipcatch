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
 * as a hint, then the full chain runs as fallback if it skips or fails. This
 * gives stale persisted keys the chance to fall through silently to a
 * different adapter when host markup changes.
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
        if ($persistedKey !== null) {
            $persisted = $this->findByKey($persistedKey);

            if ($persisted !== null) {
                $result = $persisted->extract($url, $html, $context);

                // Ambiguous from the hint means the page genuinely has multiple
                // variants; falling through to a weaker adapter would silently
                // pick the wrong one. Propagate the chooser instead.
                if ($result->isSuccess() || $result->isAmbiguous()) {
                    return $result->withAdapterKey($persisted->key());
                }
            }
        }

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
