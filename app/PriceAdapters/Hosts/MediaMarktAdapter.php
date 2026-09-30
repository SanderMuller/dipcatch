<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

/** MediaMarkt: product pages state their price in structured data (read 2026-09-30). */
final readonly class MediaMarktAdapter extends StructuredDataHostAdapter
{
    public function key(): string
    {
        return 'mediamarkt';
    }

    public function ownedHosts(): array
    {
        return ['mediamarkt.nl'];
    }
}
