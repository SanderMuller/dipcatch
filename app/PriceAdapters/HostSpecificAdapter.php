<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Marker for adapters whose persisted `adapter_key` should short-circuit
 * the chain on rechecks: host-specific adapters and the user-selector
 * adapter (whose stored CSS selectors must stay sticky). A persisted
 * GENERIC key (jsonld, microdata, opengraph, generic) does not
 * short-circuit — otherwise a shop keyed before its host gained a
 * dedicated adapter would never benefit from it.
 */
interface HostSpecificAdapter {}
