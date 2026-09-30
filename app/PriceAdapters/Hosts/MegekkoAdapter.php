<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

/** Megekko: product pages state their price in structured data (read 2026-09-30). */
final readonly class MegekkoAdapter extends StructuredDataHostAdapter
{
    public function key(): string
    {
        return 'megekko';
    }

    public function ownedHosts(): array
    {
        return ['megekko.nl'];
    }
}
