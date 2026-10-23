<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Products\SearchProductsByName;
use App\Actions\Products\SearchProductsOnTheWeb;
use App\Actions\Shops\ProbeShopUrl;
use App\Models\User;
use App\Services\ProductSearch\NameSearchResult;
use App\Services\ProductSearch\NameSearchResults;
use App\Services\ProductSearch\NameSearchVerdict;
use App\Services\ProductSearch\WebNameSearchStatus;
use App\Support\TypedLink;
use Livewire\Attributes\Locked;
use SanderMuller\FluentValidation\FluentRule;

/**
 * Step 1's one input, for a shop link or a product name. A link goes to the
 * probe; a name searches the catalogue and bol.com, and the web on request.
 * A pick sends the result's link through the same probe.
 *
 * The results and the gate's verdict are locked: the web search and a pick
 * read what was searched, never the live input. See
 * `specs/product-name-search.md` Sections 2 to 5.
 *
 * @phpstan-import-type NameSearchResultArray from NameSearchResult
 */
trait SearchesByName
{
    /** A name search takes this many characters at most; a link keeps the probe's own limits. */
    private const int NAME_MAX_LENGTH = 200;

    private const int NAME_MIN_LENGTH = 3;

    public string $query = '';

    /** The trimmed text the listed results describe. */
    #[Locked]
    public string $searchedQuery = '';

    /** @var array{items: list<NameSearchResultArray>, bolFailed: bool, fullMatch: bool, throttled: bool}|null */
    #[Locked]
    public ?array $nameResults = null;

    /** @var list<NameSearchResultArray> */
    #[Locked]
    public array $webResults = [];

    #[Locked]
    public ?WebNameSearchStatus $webStatus = null;

    /** The gate's verdict for `$searchedQuery`, so a second click does not ask Jev again. */
    #[Locked]
    public ?NameSearchVerdict $webVerdict = null;

    /** A stored web search exists for `$searchedQuery`: free at any cap. */
    #[Locked]
    public bool $webStored = false;

    #[Locked]
    public bool $webCapSpent = false;

    abstract protected function announce(string $message): void;

    abstract private function currentUser(): User;

    abstract private function focus(string $id): void;

    public function lookUp(ProbeShopUrl $probe, SearchProductsByName $search, SearchProductsOnTheWeb $web): void
    {
        $this->resetErrorBag('query');
        $this->query = trim($this->query);
        $link = TypedLink::in($this->query);

        if ($link !== null) {
            $this->clearNameSearch();
            $this->url = $link;
            $this->probe($probe);

            return;
        }

        $this->validate([
            'query' => FluentRule::string(__('Product name'))
                ->required()
                ->between(self::NAME_MIN_LENGTH, self::NAME_MAX_LENGTH),
        ]);

        // One string for the catalogue, bol.com, the gate and Serper.
        $searchText = SearchProductsOnTheWeb::searchTextOf($this->query);

        if (mb_strlen($searchText) < self::NAME_MIN_LENGTH) {
            $this->addError('query', __('Type at least 3 characters of the product name, or paste a link.'));

            return;
        }

        $user = $this->currentUser();
        $results = $search($user, $searchText);

        if ($results->throttled) {
            $this->addError('query', __('Too many searches. Wait a minute and try again.'));

            return;
        }

        $this->resetProbeState();
        $this->clearNameSearch();
        $this->searchedQuery = $this->query;
        $this->nameResults = $results->toArray();
        $this->webStored = $web->isStored($this->query);
        $this->webCapSpent = $web->capSpent($user);

        $this->announce(trans_choice('{0} Nothing found for this name.|{1} One product found.|[2,*] :count products found.', count($results->items)));
        $this->focus('name-results-heading');
    }

    public function searchTheWeb(SearchProductsOnTheWeb $web): void
    {
        if ($this->nameResults === null || $this->webStatus === WebNameSearchStatus::Found || ! $this->searchedQueryIsShown()) {
            return;
        }

        $outcome = $web($this->currentUser(), $this->searchedQuery, NameSearchResults::fromArray($this->nameResults), $this->webVerdict);

        $this->webStatus = $outcome->status;
        $this->webVerdict = $outcome->verdict ?? $this->webVerdict;
        $this->webResults = array_map(static fn (NameSearchResult $result): array => $result->toArray(), $outcome->items);
        $this->webCapSpent = $this->webCapSpent || $outcome->status === WebNameSearchStatus::CapSpent;

        $this->announce($outcome->status === WebNameSearchStatus::Found
            ? trans_choice('{0} The web search found no shops for this name.|{1} The web search found one result.|[2,*] The web search found :count results.', count($outcome->items))
            : (string) ($this->webStatus->note() ?? $this->webSearchBlock()));
        // The keyed results block is drawn again, so the clicked button is gone.
        $this->focus($outcome->status === WebNameSearchStatus::Found ? 'web-results-heading' : 'name-results-heading');
    }

    /** Sends the picked result's link through the probe. The input keeps the typed name. */
    public function pickResult(int $index, ProbeShopUrl $probe): void
    {
        $picked = $this->listedResults()[$index] ?? null;

        if ($picked === null) {
            return;
        }

        $this->url = $picked['url'];
        $this->probe($probe);

        // The list and the clicked row are gone after the probe, so focus moves on.
        if ($this->state === 'error') {
            $this->announce(__('We could not open that page.'));
            $this->focus('wizard-probe-error');

            return;
        }

        if ($this->state !== 'idle') {
            $this->focus('wizard-step-heading');
        }
    }

    /**
     * Local results first, then the web's, in the order the view lists them,
     * so a pick's index points at the row the user saw.
     *
     * @return list<NameSearchResultArray>
     */
    public function listedResults(): array
    {
        return [...$this->nameResults['items'] ?? [], ...$this->webResults];
    }

    /** Whether the input still holds the text the results describe. Off, the web search waits for a new submit. */
    public function searchedQueryIsShown(): bool
    {
        return trim($this->query) === $this->searchedQuery;
    }

    /** Why the web search button is off, or null when it is on. */
    public function webSearchBlock(): ?string
    {
        $hint = $this->webVerdict?->hint();

        if ($hint !== null || ! $this->webCapSpent || $this->webStored) {
            return $hint;
        }

        return __('You have used today’s web searches. Paste a link, or try again tomorrow.');
    }

    /** After a search, the typed name is the likely title for the hand form. */
    private function handFormTitle(string $title): string
    {
        return $title === '' && $this->searchedQuery !== '' ? trim($this->query) : $title;
    }

    private function clearNameSearch(): void
    {
        $this->reset(['searchedQuery', 'nameResults', 'webResults', 'webStatus', 'webVerdict', 'webStored', 'webCapSpent']);
    }
}
