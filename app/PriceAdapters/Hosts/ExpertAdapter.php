<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

/** Expert: product pages state their price in structured data (read 2026-09-30). */
final readonly class ExpertAdapter extends StructuredDataHostAdapter
{
    public function key(): string
    {
        return 'expert';
    }

    public function ownedHosts(): array
    {
        return ['expert.nl'];
    }
}
