<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

/** Toolstation: product pages state their price in structured data (read 2026-09-30). */
final readonly class ToolstationAdapter extends StructuredDataHostAdapter
{
    public function key(): string
    {
        return 'toolstation';
    }

    public function ownedHosts(): array
    {
        return ['toolstation.nl'];
    }
}
