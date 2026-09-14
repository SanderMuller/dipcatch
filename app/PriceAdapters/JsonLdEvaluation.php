<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * What one JSON-LD entity answered, decided once. The three questions —
 * does it answer to the pinned key, does it carry a usable offer, and how
 * precisely does it name the request — share their inputs, so
 * {@see JsonLdMatch::evaluate()} settles all three in one pass.
 */
final readonly class JsonLdEvaluation
{
    /**
     * @param  bool  $keyMatched  The caller pinned a key and this entity answers to it.
     * @param  array{0: array<string, mixed>, 1: array<string, mixed>}|null  $match
     *                            The entity and its offer, when it both names the request and states one.
     * @param  int  $precision  How precisely the entity names the request. See {@see JsonLdMatch::evaluate()}.
     */
    public function __construct(
        public bool $keyMatched,
        public ?array $match,
        public int $precision,
    ) {}
}
