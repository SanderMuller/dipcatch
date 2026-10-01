<?php declare(strict_types=1);

use App\Actions\Suggestions\SuggestShops;
use App\Models\CheckjebonChain;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Services\BolFeed\BolFeedFile;
use App\Services\BolFeed\FeedInterest;
use App\Services\BolFeed\FeedSource;
use App\Services\Suggestions\ShopSuggestion;

/**
 * A group file as bol.com ships it: gzip, `|`-separated, every field
 * quoted, a header row, and many more columns than the import reads.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: string, 4?: string, 5?: string}>  $rows  productId, ean, title, price, deliverable, condition
 */
function bolFeedGz(array $rows): string
{
    $quote = static fn (array $fields): string => implode('|', array_map(static fn (string $field): string => '"' . str_replace('"', '""', $field) . '"', $fields));
    $lines = [$quote(['productId', 'ean', 'title', 'productPageUrlNL', 'imageUrl', 'OfferNL.sellingPrice', 'OfferNL.condition', 'OfferNL.isDeliverable', 'description'])];

    foreach ($rows as $row) {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $row[2]));
        $lines[] = $quote([$row[0], $row[1], $row[2], "https://www.bol.com/nl/nl/p/{$slug}/{$row[0]}/", '', $row[3], $row[5] ?? 'new', $row[4] ?? 'Y', "Line one\nline two"]);
    }

    $path = tempnam(sys_get_temp_dir(), 'bol') . '.csv.gz';
    file_put_contents($path, gzencode(implode("\n", $lines) . "\n"));

    return $path;
}

function remiaProduct(?string $gtin = null): Product
{
    $product = Product::factory()->create(['title' => 'Remia Fritessaus classic', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://saus.test/p/1', 'pack_quantity' => '500.00', 'pack_unit' => 'ml', 'gtin' => $gtin]);

    return $product->refresh();
}

/** A downloader that hands out the given local files instead of reaching bol.com. */
function fakeBolDownloader(array $files): void
{
    config()->set('services.bol.feed.username', 'feed-user');

    app()->instance(FeedSource::class, new readonly class ($files) implements FeedSource {
        public function __construct(private array $files) {}

        public function download(string $file, string $target): void
        {
            if (! isset($this->files[$file])) {
                throw new RuntimeException("No {$file} on the fake server.");
            }

            copy($this->files[$file], $target);
        }
    });
}

it('reads only the offers that can be bought new in the Netherlands now', function (): void {
    $path = bolFeedGz([
        ['1', '8710448620013', 'Deliverable new', '2.49'],
        ['2', '8710448620024', 'Not deliverable', '2.49', 'N'],
        ['3', '8710448620031', 'Second hand', '2.49', 'Y', 'used'],
        ['4', '8710448620048', 'No NL price', ''],
    ]);

    $offers = iterator_to_array(new BolFeedFile($path)->offers(), false);

    expect(array_column($offers, 'title'))->toBe(['Deliverable new'])
        ->and($offers[0]['url'])->toBe('https://www.bol.com/nl/nl/p/deliverable-new/1/');
});

it('refuses a file whose columns changed, rather than reading the wrong ones', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'bol') . '.csv.gz';
    file_put_contents($path, gzencode("\"id\"|\"name\"\n\"1\"|\"x\"\n"));

    iterator_to_array(new BolFeedFile($path)->offers());
})->throws(RuntimeException::class, "no 'productId' column");

it('keeps a feed row that a tracked product could be offered, by name or by barcode, and drops the rest', function (): void {
    remiaProduct(gtin: '8710448620013');
    $interest = FeedInterest::build(app(SuggestShops::class));

    expect($interest->wants('Remia Fritessaus Classic 500 ml', '0000000000000'))->toBeTrue()
        ->and($interest->wants('Totally different name', '08710448620013'))->toBeTrue()
        ->and($interest->wants('Calvé Pindasaus 650 ml', '8712100325328'))->toBeFalse()
        // One shared word is not enough to be worth keeping.
        ->and($interest->wants('Remia Mayonaise 500 ml', '8712100325328'))->toBeFalse();
});

it('imports the wanted rows as the bol chain, and offers them as a suggestion', function (): void {
    $product = remiaProduct();
    fakeBolDownloader(['product-feed_supermarket-v2.csv.gz' => bolFeedGz([
        ['9300000001', '8710448620013', 'Remia Fritessaus Classic 500 ml', '1.99'],
        ['9300000002', '8712100325328', 'Calvé Pindasaus 650 ml', '3.49'],
    ])]);

    $this->artisan('dipcatch:import-bol-feed', ['--group' => ['supermarket']])->assertSuccessful();

    expect(CheckjebonPrice::query()->where('supermarket', 'bol')->pluck('name')->all())->toBe(['Remia Fritessaus Classic 500 ml'])
        ->and(CheckjebonChain::query()->where('chain', 'bol')->value('base_url'))->toBe('https://www.bol.com/nl/nl/p/');

    $suggestions = app(SuggestShops::class)($product, verify: false);

    expect(collect($suggestions)->map(fn (ShopSuggestion $suggestion): string => "{$suggestion->chainLabel} {$suggestion->url}")->all())
        ->toBe(['bol.com https://www.bol.com/nl/nl/p/remia-fritessaus-classic-500-ml/9300000001/'])
        ->and($suggestions[0]->trackable)->toBeTrue();
});

it('offers a bol row with the same barcode as a tracked shop, whatever its name', function (): void {
    $product = remiaProduct(gtin: '8710448620013');
    fakeBolDownloader(['product-feed_supermarket-v2.csv.gz' => bolFeedGz([
        ['9300000003', '08710448620013', 'Frietsaus van Remia, de klassieke', '1.99'],
    ])]);

    $this->artisan('dipcatch:import-bol-feed', ['--group' => ['supermarket']])->assertSuccessful();

    $suggestions = app(SuggestShops::class)($product, verify: false);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->score)->toBe(1.0);
});

it('keeps the rows of the last import when a group fails, and removes stale rows after a full run', function (): void {
    remiaProduct();
    CheckjebonPrice::query()->create(['supermarket' => 'bol', 'external_id' => 'old', 'name' => 'Remia Fritessaus classic 1 l', 'price' => '2.99', 'link' => 'old/1/', 'refreshed_at' => now()->subDay()]);

    // Every group missing: nothing is removed.
    fakeBolDownloader([]);
    $this->artisan('dipcatch:import-bol-feed')->assertFailed();
    expect(CheckjebonPrice::query()->where('supermarket', 'bol')->count())->toBe(1);

    // Every group read: the row no longer in the feed goes.
    $empty = bolFeedGz([]);
    fakeBolDownloader(array_fill_keys(array_map(fn (string $group): string => "product-feed_{$group}-v2.csv.gz", ['supermarket', 'daily-care', 'health', 'pet', 'perfumery', 'baby']), $empty));
    $this->artisan('dipcatch:import-bol-feed')->assertSuccessful();
    expect(CheckjebonPrice::query()->where('supermarket', 'bol')->count())->toBe(0);
});

it('does nothing without feed credentials', function (): void {
    config()->set('services.bol.feed.username', '');

    $this->artisan('dipcatch:import-bol-feed')->assertSuccessful()->expectsOutputToContain('No bol.com feed username');
});
