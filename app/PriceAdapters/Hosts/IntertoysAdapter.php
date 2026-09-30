<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

/** Intertoys: product pages state their price in structured data (read 2026-09-30). */
final readonly class IntertoysAdapter extends StructuredDataHostAdapter
{
    public function key(): string
    {
        return 'intertoys';
    }

    public function ownedHosts(): array
    {
        return ['intertoys.nl'];
    }
}
