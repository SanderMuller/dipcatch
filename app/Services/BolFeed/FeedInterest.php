<?php declare(strict_types=1);

namespace App\Services\BolFeed;

use App\Actions\Suggestions\SuggestShops;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Suggestions\QueryTokens;
use App\Support\Gtin;

/**
 * Which rows of bol.com's product feed could ever become a suggestion, so
 * the import keeps those and drops the millions of others: a row whose
 * barcode a tracked shop reports, or whose title holds, as whole words,
 * the prefilter words of a tracked product's query
 * ({@see QueryTokens::prefilterNeedles()}) and then scores high enough to be
 * offered ({@see SuggestShops::couldOffer()}). Built once per import from
 * every active product.
 */
final readonly class FeedInterest
{
    /**
     * @param  array<string, true>  $gtins  barcodes, leading zeros stripped
     * @param  array<string, list<int>>  $queriesByWord  prefilter word → the queries that look for it
     * @param  array<int, int>  $wordsNeeded  query → how many of its words a title must contain
     * @param  array<int, QueryTokens>  $queries
     */
    private function __construct(
        private SuggestShops $suggest,
        private array $gtins,
        private array $queriesByWord,
        private array $wordsNeeded,
        private array $queries,
    ) {}

    public static function build(SuggestShops $suggest): self
    {
        $gtins = [];

        foreach (Shop::query()->whereNotNull('gtin')->distinct()->pluck('gtin') as $gtin) {
            if (is_string($gtin) && $gtin !== '') {
                $gtins[ltrim($gtin, '0')] = true;
            }
        }

        $queriesByWord = [];
        $wordsNeeded = [];
        $queries = [];

        foreach (Product::query()->where('active', true)->with('shops')->lazyById() as $product) {
            foreach ($suggest->queriesFor($product) as $query) {
                $needles = $query->prefilterNeedles();

                if ($needles === []) {
                    continue;
                }

                $index = count($wordsNeeded);
                $wordsNeeded[$index] = min(2, count($needles));
                $queries[$index] = $query;

                foreach ($needles as $needle) {
                    $queriesByWord[$needle][] = $index;
                }
            }
        }

        return new self($suggest, $gtins, $queriesByWord, $wordsNeeded, $queries);
    }

    public function isEmpty(): bool
    {
        return $this->gtins === [] && $this->wordsNeeded === [];
    }

    public function wants(string $title, string $ean): bool
    {
        $gtin = Gtin::normalize($ean);

        if ($gtin !== null && isset($this->gtins[ltrim($gtin, '0')])) {
            return true;
        }

        $hits = [];
        $candidates = [];

        foreach (array_keys(QueryTokens::of($title)->tokens) as $word) {
            foreach ($this->queriesByWord[(string) $word] ?? [] as $query) {
                $hits[$query] = ($hits[$query] ?? 0) + 1;

                if ($hits[$query] === $this->wordsNeeded[$query]) {
                    $candidates[] = $this->queries[$query];
                }
            }
        }

        return $candidates !== [] && $this->suggest->couldOffer($title, $candidates);
    }
}
