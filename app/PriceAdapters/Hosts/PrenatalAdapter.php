<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

/** Prénatal: product pages state their price in structured data (read 2026-09-30). */
final readonly class PrenatalAdapter extends StructuredDataHostAdapter
{
    public function key(): string
    {
        return 'prenatal';
    }

    public function ownedHosts(): array
    {
        return ['prenatal.nl'];
    }
}
