<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

/** Hubo: product pages state their price in structured data (read 2026-09-30). */
final readonly class HuboAdapter extends StructuredDataHostAdapter
{
    public function key(): string
    {
        return 'hubo';
    }

    public function ownedHosts(): array
    {
        return ['hubo.nl'];
    }
}
