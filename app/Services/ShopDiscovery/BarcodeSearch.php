<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Jobs\SearchProductBarcode;
use App\Models\Product;
use App\Models\WebSearch;
use Closure;

/**
 * Web discovery's second search: the product's barcode. Shops that list a
 * product under another name than its title are often found by the barcode
 * they print on the page.
 */
final readonly class BarcodeSearch
{
    public function __construct(private WebSearches $searches) {}

    /**
     * Hands the results of the product's stored barcode search worth a
     * finding to `$store`, filtered as the title search's are. Without a
     * fresh stored search, the search runs in its own job and discovery runs
     * again after it: with the title search and the first check, one job
     * would outrun its timeout. Nothing for a product without a barcode.
     *
     * @param  Closure(WebSearch, list<array{url: string, url_hash: string, host: string, title: string, snippet: string}>): int  $store
     */
    public function run(Product $product, Closure $store): void
    {
        $barcode = self::barcodeOf($product);
        $search = $barcode === null ? null : $this->searches->fresh(WebSearch::hashOf($barcode));

        if ($barcode !== null && ! $search instanceof WebSearch) {
            SearchProductBarcode::queueFor($product);

            return;
        }

        if ($search instanceof WebSearch) {
            $store($search, WebResultFilter::withoutKnownHosts($product, WebResultFilter::keep($product, $search)));
        }
    }

    /** Searches the product's barcode. False without a barcode, or when no search could be made. */
    public function search(Product $product): bool
    {
        $barcode = self::barcodeOf($product);

        return $barcode !== null && $this->searches->forQuery($barcode) instanceof WebSearch;
    }

    /** Whether a run for the product would spend a search on its barcode. */
    public function isDue(Product $product): bool
    {
        $barcode = self::barcodeOf($product);

        return $barcode !== null && ! $this->searches->fresh(WebSearch::hashOf($barcode)) instanceof WebSearch;
    }

    /**
     * The barcode most of the product's shops report, so one paid search
     * covers it. An EAN-13 and its GTIN-14 form count as one; a tie goes to
     * the lowest, so the same barcode is searched each run. The query is the
     * form a shop stores, the 13-digit one when there is one: shops print
     * that, and an EAN-8 padded to 13 digits is a barcode no shop prints.
     */
    private static function barcodeOf(Product $product): ?string
    {
        $counts = [];
        $forms = [];

        foreach ($product->shops as $shop) {
            $gtin = $shop->gtin ?? '';
            $key = ltrim($gtin, '0');

            if ($key === '' || ! ctype_digit($gtin)) {
                continue;
            }

            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $forms[$key] = strlen($forms[$key] ?? '') === 13 ? $forms[$key] : $gtin;
        }

        if ($counts === []) {
            return null;
        }

        uksort($counts, static fn (int|string $a, int|string $b): int => $counts[$b] <=> $counts[$a] ?: strcmp((string) $a, (string) $b));

        return $forms[array_key_first($counts)];
    }
}
