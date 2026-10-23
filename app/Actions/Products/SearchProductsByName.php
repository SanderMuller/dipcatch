<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\CatalogueLink;
use App\Models\CheckjebonChain;
use App\Models\CheckjebonPrice;
use App\Models\User;
use App\Services\BolApi\BolApiFailed;
use App\Services\BolApi\BolCatalogClient;
use App\Services\BolApi\BolProduct;
use App\Services\ProductSearch\NameSearchResult;
use App\Services\ProductSearch\NameSearchResults;
use App\Services\ProductSearch\NameSearchSource;
use App\Services\Suggestions\QueryTokens;
use App\Support\SupermarketChains;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Products for a name the user typed in the add-product wizard, from the
 * supermarket catalogue and bol.com, at no cost per search. A pick sends
 * the result's link through the normal probe.
 *
 * Bound per request, so the fresh chains and the `pg_trgm` check are read
 * once per request. See `specs/product-name-search.md` Section 3.
 *
 * @phpstan-import-type NameSearchResultArray from NameSearchResult
 */
final class SearchProductsByName
{
    /** Catalogue rows the database returns for PHP to rank. */
    private const int CANDIDATES = 50;

    private const int CATALOGUE_SHOWN = 8;

    private const int BOL_SHOWN = 5;

    /** A word shorter than this makes a useless `LIKE`. */
    private const int MIN_NEEDLE_LENGTH = 3;

    /** Words past this add `LIKE` terms, not a better match. */
    private const int MAX_NEEDLES = 10;

    private const int SEARCHES_PER_MINUTE = 20;

    /**
     * Below bol's 10 a second per endpoint, so the name search alone never
     * hits it. `LookUpBolOffers` does not spend this, so the two together
     * can still get a 429, which shows the bol note.
     */
    private const int BOL_CALLS_PER_SECOND = 5;

    private const int BOL_CACHE_SECONDS = 3600;

    private const int BOL_LOCK_WAIT_SECONDS = 5;

    /** Covers a bol login and a search, each with a 10-second timeout. */
    private const int BOL_LOCK_SECONDS = 30;

    /** @var array<string, CheckjebonChain>|null */
    private ?array $chains = null;

    /**
     * @param  bool|null  $trigrams  Whether `pg_trgm` is installed; null reads it from the database.
     */
    public function __construct(
        private readonly BolCatalogClient $bol,
        private ?bool $trigrams = null,
    ) {}

    public function __invoke(User $user, string $text): NameSearchResults
    {
        $limiterKey = "name-search:local:{$user->id}";

        if (RateLimiter::tooManyAttempts($limiterKey, self::SEARCHES_PER_MINUTE)) {
            return NameSearchResults::throttled();
        }

        RateLimiter::hit($limiterKey);

        [$bolResults, $bolFailed] = $this->bolResults($text);
        [$catalogue, $fullMatch] = $this->catalogueResults($text);

        // A bol.com API result has a photo and today's offer: it replaces
        // the feed row for the same barcode.
        $bolEans = array_filter(array_map(static fn (NameSearchResult $result): ?string => $result->ean, $bolResults));
        $catalogue = array_values(array_filter(
            $catalogue,
            static fn (NameSearchResult $result): bool => $result->ean === null || ! in_array($result->ean, $bolEans, strict: true),
        ));

        return new NameSearchResults(
            items: [...array_slice($catalogue, 0, self::CATALOGUE_SHOWN), ...$bolResults],
            bolFailed: $bolFailed,
            fullMatch: $fullMatch,
        );
    }

    /**
     * Every ranked catalogue result, and whether one of the candidates holds
     * every word of the text. Covered rows come first, then the overlap.
     *
     * @return array{0: list<NameSearchResult>, 1: bool}
     */
    private function catalogueResults(string $text): array
    {
        $needles = self::needles($text);
        $chains = array_filter(
            $this->chains ??= CheckjebonChain::freshByKey(),
            static fn (CheckjebonChain $chain): bool => SupermarketChains::isTrackable($chain->chain),
        );

        if ($needles === [] || $chains === []) {
            return [[], false];
        }

        $search = QueryTokens::ofSearchText($text);
        $ranked = [];

        foreach ($this->candidates($text, $needles, $chains) as $position => $row) {
            $tokens = QueryTokens::ofCatalogueRow($row->name, $row->size);
            $ranked[] = [$search->coveredBy($tokens), $search->overlapWith($tokens), $position, $row];
        }

        usort($ranked, static fn (array $a, array $b): int => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

        return [
            array_map(fn (array $entry): NameSearchResult => $this->catalogueResult($entry[3], $chains[$entry[3]->supermarket]), $ranked),
            array_any($ranked, static fn (array $entry): bool => $entry[0]),
        ];
    }

    /**
     * The rows that hold at least one word, best first by the number of
     * words they hold, then by trigram similarity. Only a prefilter: PHP
     * ranks them. Plain `lower(name) like ?` per needle, so PostgreSQL can
     * serve each from the trigram index.
     *
     * @param  non-empty-list<non-empty-list<string>>  $needles
     * @param  array<string, CheckjebonChain>  $chains
     * @return Collection<int, CheckjebonPrice>
     */
    private function candidates(string $text, array $needles, array $chains): Collection
    {
        $patterns = array_merge(...$needles);
        $anyWord = implode(' or ', array_fill(0, count($patterns), 'lower(name) like ?'));
        $wordsHeld = implode(' + ', array_map(
            static fn (array $forms): string => 'case when (' . implode(' or ', array_fill(0, count($forms), 'lower(name) like ?')) . ') then 1 else 0 end',
            $needles,
        ));

        /** @var Collection<int, CheckjebonPrice> $rows */
        $rows = CheckjebonPrice::query()
            ->select(['checkjebon_prices.id', 'supermarket', 'external_id', 'name', 'size', 'price', 'link', 'ean'])
            ->addSelect([
                'live_url' => CatalogueLink::query()->select('url')->whereColumn('chain', 'checkjebon_prices.supermarket')->whereColumn('external_id', 'checkjebon_prices.external_id')->where('alive', true)->limit(1),
            ])
            ->whereIn('supermarket', array_keys($chains))
            ->whereNotNull('link')
            ->where('link', '!=', '')
            ->whereRaw("({$anyWord})", $patterns)
            // A page a check found gone would only fail the probe on a pick.
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from(new CatalogueLink()->getTable())
                    ->whereColumn('chain', 'checkjebon_prices.supermarket')
                    ->whereColumn('external_id', 'checkjebon_prices.external_id')
                    ->where('alive', false)
                    ->where('checked_at', '>=', CatalogueLink::validFrom());
            })
            ->orderByRaw("({$wordsHeld}) desc", $patterns)
            ->when($this->hasTrigrams(), static fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->orderByRaw('similarity(lower(name), ?) desc', [mb_strtolower($text)]))
            ->orderBy('checkjebon_prices.id')
            ->limit(self::CANDIDATES)
            ->get();

        return $rows;
    }

    /**
     * The `LIKE` patterns per word of three characters or more: the word in
     * lowercase as typed, and its ASCII form when that differs, so "café"
     * matches both "Café" and "Cafe". Escaped for PostgreSQL's default
     * escape character.
     *
     * @return list<non-empty-list<string>>
     */
    private static function needles(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}+]+/u', mb_strtolower($text), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $needles = [];

        foreach (array_unique($words) as $word) {
            if (mb_strlen($word) < self::MIN_NEEDLE_LENGTH) {
                continue;
            }

            if (count($needles) === self::MAX_NEEDLES) {
                break;
            }

            $forms = array_unique([$word, mb_strtolower(Str::ascii($word))]);

            $needles[] = array_values(array_map(
                static fn (string $form): string => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $form) . '%',
                $forms,
            ));
        }

        return $needles;
    }

    private function hasTrigrams(): bool
    {
        return $this->trigrams ??= DB::getDriverName() === 'pgsql'
            && DB::table('pg_extension')->where('extname', 'pg_trgm')->exists();
    }

    private function catalogueResult(CheckjebonPrice $row, CheckjebonChain $chain): NameSearchResult
    {
        $liveUrl = $row->getAttribute('live_url');

        return new NameSearchResult(
            source: NameSearchSource::Catalogue,
            title: $row->name,
            shopLabel: $chain->label,
            host: SupermarketChains::hosts($chain->chain, $chain->base_url)[0] ?? '',
            url: is_string($liveUrl) ? $liveUrl : $chain->productUrl((string) $row->link),
            ean: $row->ean,
            price: $row->price,
            packSize: $row->size !== null && $row->size !== '' ? $row->size : null,
        );
    }

    /**
     * bol.com's own search, kept per text for an hour. One request per text
     * fills the cache; the others wait for it. A failure is not kept, and
     * the API call is already counted as failed in the usage log.
     *
     * @return array{0: list<NameSearchResult>, 1: bool}  The results, and whether bol failed.
     */
    private function bolResults(string $text): array
    {
        if (! BolCatalogClient::configured()) {
            return [[], false];
        }

        $cacheKey = 'name-search:bol:' . hash('sha256', self::normalised($text));
        $results = self::cachedBol($cacheKey);

        if ($results === null) {
            try {
                Cache::lock("{$cacheKey}:lock", self::BOL_LOCK_SECONDS)->block(self::BOL_LOCK_WAIT_SECONDS, function () use ($cacheKey, $text): void {
                    if (self::cachedBol($cacheKey) === null) {
                        $this->searchBol($cacheKey, $text);
                    }
                });
            } catch (LockTimeoutException|BolApiFailed) {
                return [[], true];
            }

            // Still empty when the app-wide limit was spent.
            $results = self::cachedBol($cacheKey);
        }

        if ($results === null) {
            return [[], true];
        }

        return [array_values(array_map(NameSearchResult::fromArray(...), $results)), false];
    }

    private function searchBol(string $cacheKey, string $text): void
    {
        if (RateLimiter::tooManyAttempts('name-search:bol', self::BOL_CALLS_PER_SECOND)) {
            return;
        }

        RateLimiter::hit('name-search:bol', 1);

        $results = array_map(
            static fn (BolProduct $product): array => self::bolResult($product)->toArray(),
            array_slice($this->bol->search($text, self::BOL_SHOWN), 0, self::BOL_SHOWN),
        );

        Cache::put($cacheKey, $results, self::BOL_CACHE_SECONDS);
    }

    /**
     * @return list<NameSearchResultArray>|null
     */
    private static function cachedBol(string $cacheKey): ?array
    {
        /** @var list<NameSearchResultArray>|null $cached */
        $cached = Cache::get($cacheKey);

        return is_array($cached) ? $cached : null;
    }

    private static function bolResult(BolProduct $product): NameSearchResult
    {
        return new NameSearchResult(
            source: NameSearchSource::Bol,
            title: $product->title,
            shopLabel: 'bol.com',
            host: 'bol.com',
            url: $product->url,
            ean: ltrim($product->ean, '0') ?: null,
            price: $product->price,
            photoUrl: $product->imageUrl,
        );
    }

    private static function normalised(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
