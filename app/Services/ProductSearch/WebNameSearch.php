<?php declare(strict_types=1);

namespace App\Services\ProductSearch;

/** The answer to one "Search the web" request, with the gate's verdict for the wizard to keep. */
final readonly class WebNameSearch
{
    /**
     * @param  list<NameSearchResult>  $items
     * @param  NameSearchVerdict|null  $verdict  Null when the gate did not run: a stored search, or a stop before it.
     */
    public function __construct(
        public WebNameSearchStatus $status,
        public array $items = [],
        public ?NameSearchVerdict $verdict = null,
    ) {}
}
