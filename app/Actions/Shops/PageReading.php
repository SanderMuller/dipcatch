<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\PriceAdapters\ShopSnapshot;
use Carbon\CarbonImmutable;

/**
 * One successful read of a shop page, as {@see PageReadings} keeps it.
 */
final readonly class PageReading
{
    public function __construct(
        public ShopSnapshot $snapshot,
        public ?string $adapterKey,
        public ?string $imageUrl,
        public CarbonImmutable $readAt,
    ) {}

    /**
     * @return array{snapshot: ShopSnapshot, adapter_key: ?string, image_url: ?string, read_at: int}
     */
    public function toStored(): array
    {
        return [
            'snapshot' => $this->snapshot,
            'adapter_key' => $this->adapterKey,
            'image_url' => $this->imageUrl,
            'read_at' => $this->readAt->getTimestamp(),
        ];
    }

    /** Null for anything that is not a reading this class stored. */
    public static function fromStored(mixed $stored): ?self
    {
        if (! is_array($stored) || ! ($stored['snapshot'] ?? null) instanceof ShopSnapshot || ! is_int($stored['read_at'] ?? null)) {
            return null;
        }

        $adapterKey = $stored['adapter_key'] ?? null;
        $imageUrl = $stored['image_url'] ?? null;

        return new self(
            $stored['snapshot'],
            is_string($adapterKey) ? $adapterKey : null,
            is_string($imageUrl) ? $imageUrl : null,
            CarbonImmutable::createFromTimestamp($stored['read_at']),
        );
    }
}
