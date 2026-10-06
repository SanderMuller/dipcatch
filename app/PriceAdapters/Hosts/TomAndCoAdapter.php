<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;

/**
 * Tom&Co: product pages state their price in structured data, and the
 * JSON-LD says `InStock` for a product that can only be picked up in a
 * store. The home-delivery block says so: `delivery-info--deliverytime`
 * carries `is-not-available` and the note "Momenteel niet leverbaar"
 * (read 2026-10-06). A product nobody can have delivered is not a place
 * to buy online, so that page reads out of stock.
 */
final readonly class TomAndCoAdapter extends StructuredDataHostAdapter
{
    public function key(): string
    {
        return 'tomandco';
    }

    public function ownedHosts(): array
    {
        return ['tomandco.com'];
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $result = parent::extract($url, $html, $context);
        $snapshot = $result->snapshot;

        if (! $result->isSuccess() || $snapshot === null || ! self::deliveryUnavailable($html)) {
            return $result;
        }

        return $result->withSnapshot($snapshot->withStock(inStock: false, stockSignal: 'markup: home delivery is-not-available'));
    }

    private static function deliveryUnavailable(string $html): bool
    {
        if (preg_match('/class="[^"]*\bdelivery-info--deliverytime\b[^"]*"/', $html, $match) !== 1) {
            return false;
        }

        return preg_match('/(^|\s)is-not-available(\s|")/', $match[0]) === 1;
    }
}
