<?php declare(strict_types=1);

namespace App\Actions\Suggestions;

/**
 * A dataset row the suggestions matched on its name, waiting for Jev to say
 * whether it sells the same product and pack.
 */
final readonly class UncheckedSuggestion
{
    public function __construct(
        public string $chainLabel,
        public string $chain,
        public string $externalId,
        public string $name,
        public ?string $size,
        public string $price,
        public float $score,
    ) {}
}
