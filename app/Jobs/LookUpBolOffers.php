<?php declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Suggestions\SuggestShops;
use App\Models\Product;
use App\Services\BolApi\BolApiFailed;
use App\Services\BolApi\BolCatalogClient;
use App\Services\BolApi\BolProduct;
use App\Services\BolFeed\BolCatalogRows;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Asks bol.com's Catalog API what it sells of a product, the moment the
 * product is added or gets a shop, so bol.com is suggested without waiting
 * for the daily feed import. By barcode first, which is exact; by name
 * when no barcode finds it. Only offers that would be suggested, and that
 * carry a barcode the daily refresh can ask for again, are stored, in the
 * same catalogue rows the feed writes.
 */
#[Tries(3)]
#[Timeout(30)]
final class LookUpBolOffers implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Barcodes asked per product; a product rarely has more. */
    private const int BARCODES = 3;

    public function __construct(public string $productId) {}

    public static function dispatchFor(Product $product): void
    {
        if (BolCatalogClient::configured() && $product->active && strcasecmp($product->currency, 'EUR') === 0) {
            dispatch(new self((string) $product->id));
        }
    }

    public function uniqueId(): string
    {
        return "look-up-bol-offers:{$this->productId}";
    }

    public function handle(BolCatalogClient $bol, SuggestShops $suggest): void
    {
        $product = Product::query()->with('shops')->find($this->productId);

        if (! $product instanceof Product || ! $product->active) {
            return;
        }

        try {
            $found = $this->byBarcode($bol, $product) ?: $this->byName($bol, $suggest, $product);
        } catch (BolApiFailed $e) {
            // bol counts per second, so a short wait suffices.
            if ($e->rateLimited()) {
                $this->release(10);

                return;
            }

            throw $e;
        }

        $now = now();
        BolCatalogRows::store(array_values(array_filter(
            array_map(
                static fn (BolProduct $offer): ?array => $offer->price === null ? null : BolCatalogRows::row(self::productIdOf($offer->url), $offer->ean, $offer->title, $offer->url, $offer->price, $now),
                $found,
            ),
            static fn (?array $row): bool => $row !== null && $row['ean'] !== null,
        )), $now);
    }

    /**
     * @return list<BolProduct>
     */
    private function byBarcode(BolCatalogClient $bol, Product $product): array
    {
        $found = [];

        foreach (array_slice(SuggestShops::gtinsOf($product), 0, self::BARCODES) as $gtin) {
            $offer = $bol->findByEan($gtin);

            if ($offer instanceof BolProduct) {
                $found[] = $offer;
            }
        }

        return $found;
    }

    /**
     * @return list<BolProduct>
     */
    private function byName(BolCatalogClient $bol, SuggestShops $suggest, Product $product): array
    {
        $queries = $suggest->queriesFor($product);

        if ($queries === []) {
            return [];
        }

        return array_values(array_filter(
            $bol->search((string) $product->title),
            static fn (BolProduct $offer): bool => $suggest->couldOffer($offer->title, $queries),
        ));
    }

    private static function productIdOf(string $url): string
    {
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

        return (string) end($segments);
    }
}
