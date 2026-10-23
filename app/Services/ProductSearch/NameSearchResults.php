<?php declare(strict_types=1);

namespace App\Services\ProductSearch;

/**
 * The answer to one name search: the results, and what the view must say
 * about the sources that did not answer. Stored as an array in Livewire
 * state, so the web search gate reads `fullMatch` without a second query.
 *
 * @phpstan-import-type NameSearchResultArray from NameSearchResult
 */
final readonly class NameSearchResults
{
    /**
     * @param  list<NameSearchResult>  $items
     * @param  bool  $bolFailed  bol.com was asked and did not answer.
     * @param  bool  $fullMatch  A catalogue row holds every word of the text, before the display cut.
     * @param  bool  $throttled  The user searched too often; nothing was searched.
     */
    public function __construct(
        public array $items = [],
        public bool $bolFailed = false,
        public bool $fullMatch = false,
        public bool $throttled = false,
    ) {}

    public static function throttled(): self
    {
        return new self(throttled: true);
    }

    /**
     * @return array{items: list<NameSearchResultArray>, bolFailed: bool, fullMatch: bool, throttled: bool}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (NameSearchResult $item): array => $item->toArray(), $this->items),
            'bolFailed' => $this->bolFailed,
            'fullMatch' => $this->fullMatch,
            'throttled' => $this->throttled,
        ];
    }

    /**
     * @param  array{items: list<NameSearchResultArray>, bolFailed: bool, fullMatch: bool, throttled: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            items: array_map(NameSearchResult::fromArray(...), $data['items']),
            bolFailed: $data['bolFailed'],
            fullMatch: $data['fullMatch'],
            throttled: $data['throttled'],
        );
    }
}
