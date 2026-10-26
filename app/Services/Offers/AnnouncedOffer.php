<?php declare(strict_types=1);

namespace App\Services\Offers;

use Carbon\CarbonImmutable;

/**
 * One product's offer as a shop announces it before it starts.
 */
final readonly class AnnouncedOffer
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?string $offerPrice,
        public ?string $text,
    ) {}

    /**
     * In UTC, so two records that state one instant with different offsets
     * compare as the same offer.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: ?string}
     */
    public function toArray(): array
    {
        return [$this->startsAt->utc()->toIso8601String(), $this->endsAt->utc()->toIso8601String(), $this->offerPrice, $this->text];
    }

    /** @param  array{0: string, 1: string, 2: ?string, 3: ?string}  $values */
    public static function fromArray(array $values): self
    {
        return new self(CarbonImmutable::parse($values[0]), CarbonImmutable::parse($values[1]), $values[2], $values[3]);
    }
}
