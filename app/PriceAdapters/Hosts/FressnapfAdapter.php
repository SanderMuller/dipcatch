<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

/**
 * Fressnapf and its French shop Maxi Zoo: product pages state their price in
 * structured data, the sale price apart from the recommended one (read
 * 2026-10-06).
 */
final readonly class FressnapfAdapter extends StructuredDataHostAdapter
{
    public function key(): string
    {
        return 'fressnapf';
    }

    public function ownedHosts(): array
    {
        return ['fressnapf.de', 'maxizoo.fr'];
    }
}
