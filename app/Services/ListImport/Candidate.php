<?php declare(strict_types=1);

namespace App\Services\ListImport;

/** One product a list line may mean: a tracked product, or a catalogue row. */
final readonly class Candidate
{
    public function __construct(
        public string $key,
        public string $name,
        public ?string $size,
        public string $chain,
        public float $score,
        public bool $tracked = false,
        public ?string $price = null,
    ) {}

    public function label(): string
    {
        $size = $this->size === null || $this->size === '' ? '' : ", {$this->size}";

        $price = $this->price === null ? '' : ", €{$this->price}";

        return ($this->tracked ? 'Already tracked: ' : '') . "{$this->name}{$size}{$price} ({$this->chain})";
    }

    /**
     * Whether this is the product a test line expects: every `&`-separated
     * part appears in the name and size, and with a chain given, the row is
     * that chain's or a tracked product. `-` expects nothing.
     */
    public function meets(string $expected, ?string $chain): bool
    {
        if ($expected === '-' || ($chain !== null && ! $this->tracked && $this->chain !== $chain)) {
            return false;
        }

        $haystack = CatalogueCandidates::normalise("{$this->name} {$this->size}");

        return array_all(explode('&', $expected), fn (string $part): bool => str_contains($haystack, CatalogueCandidates::normalise($part)));
    }
}
