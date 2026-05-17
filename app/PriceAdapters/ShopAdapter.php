<?php declare(strict_types=1);

namespace App\PriceAdapters;

interface ShopAdapter
{
    /**
     * Stable identifier persisted in `offers.adapter_key`. The resolver uses
     * this on re-checks to run the previously-winning adapter first.
     */
    public function key(): string;

    /**
     * Tri-state extraction (see {@see ExtractionResult}). Adapters that
     * don't need it should ignore $context.
     */
    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult;
}
