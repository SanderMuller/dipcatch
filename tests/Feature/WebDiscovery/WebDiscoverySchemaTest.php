<?php declare(strict_types=1);

use App\Enums\WebFindingStatus;
use App\Models\Product;
use App\Models\Shop;
use App\Models\WebDiscovery;
use App\Models\WebSearch;
use App\Models\WebShopFinding;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<model-property<WebShopFinding>, mixed>  $attributes
 */
function webFinding(Product $product, array $attributes = []): WebShopFinding
{
    return WebShopFinding::query()->forceCreate([
        'product_id' => $product->id,
        'url' => 'https://shop.test/p/' . fake()->unique()->numberBetween(1, 99999),
        'url_hash' => hash('sha256', (string) fake()->unique()->uuid()),
        'host' => 'shop.test',
        'search_title' => 'Coffee beans 1 kg',
        'status' => WebFindingStatus::Proposed,
        'fingerprint' => WebShopFinding::fingerprintFor($product),
        ...$attributes,
    ]);
}

it('keeps one finding per product and URL', function (): void {
    $product = Product::factory()->create();
    webFinding($product, ['url_hash' => str_repeat('a', 64)]);

    // Inside a savepoint: Postgres aborts the surrounding transaction on a
    // violation, and the test goes on after it.
    expect(fn () => DB::transaction(fn (): WebShopFinding => webFinding($product, ['url_hash' => str_repeat('a', 64)])))
        ->toThrow(UniqueConstraintViolationException::class);

    // Another product may find the same page.
    webFinding(Product::factory()->create(), ['url_hash' => str_repeat('a', 64)]);
});

it('removes the findings and the discovery state with the product, and keeps the shared search', function (): void {
    $product = Product::factory()->create();
    $search = WebSearch::query()->create(['query_hash' => WebSearch::hashOf('Coffee', 'nl'), 'query' => 'Coffee', 'results' => [], 'searched_at' => now()]);
    webFinding($product, ['web_search_id' => $search->id]);
    WebDiscovery::markQueued($product);

    $product->delete();

    expect(WebShopFinding::query()->count())->toBe(0)
        ->and(WebDiscovery::query()->count())->toBe(0)
        ->and(WebSearch::query()->count())->toBe(1);
});

it('fingerprints the title and the distinct pack sizes, not the shops or their barcodes', function (): void {
    $product = Product::factory()->create(['title' => 'Coffee beans']);
    Shop::factory()->for($product)->create(['pack_quantity' => '1000.00', 'pack_unit' => 'g', 'gtin' => '8711000000001']);
    $before = WebShopFinding::fingerprintFor($product->refresh());

    // Another shop in the same pack, with a barcode the product did not have.
    Shop::factory()->for($product)->create(['pack_quantity' => '1000.00', 'pack_unit' => 'g', 'gtin' => '8711000000002']);
    expect(WebShopFinding::fingerprintFor($product->refresh()))->toBe($before);

    Shop::factory()->for($product)->create(['pack_quantity' => '500.00', 'pack_unit' => 'g']);
    expect(WebShopFinding::fingerprintFor($product->refresh()))->not->toBe($before);

    $product->update(['title' => 'Coffee beans dark']);
    expect(WebShopFinding::fingerprintFor($product->refresh()))->not->toBe($before);
});

it('fingerprints the owner\'s country, which both checks ask about', function (): void {
    $product = Product::factory()->create(['title' => 'Coffee beans']);
    $before = WebShopFinding::fingerprintFor($product);

    $product->user->forceFill(['country' => 'be'])->save();

    expect(WebShopFinding::fingerprintFor($product->refresh()))->not->toBe($before);
});

it('treats a removed barcode as stale and an added one as not', function (): void {
    $finding = new WebShopFinding(['checked_gtins' => ['1', '2']]);

    expect($finding->hasStaleGtins(['1', '2', '3']))->toBeFalse()
        ->and($finding->hasStaleGtins(['1']))->toBeTrue();
});

it('shows only proposed, visible, current findings at shops the product does not track', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['url' => 'https://tracked.test/p/1', 'host' => 'tracked.test', 'gtin' => '8711000000001']);
    $product->refresh();

    $shown = webFinding($product, ['host' => 'new.test', 'url' => 'https://new.test/p/1']);
    webFinding($product, ['host' => 'new2.test', 'status' => WebFindingStatus::Declined]);
    webFinding($product, ['host' => 'new3.test', 'dismissed_at' => now()]);
    webFinding($product, ['host' => 'new4.test', 'fingerprint' => str_repeat('0', 64)]);
    webFinding($product, ['host' => 'tracked.test', 'url' => 'https://www.tracked.test/p/2']);
    webFinding($product, ['host' => 'new5.test', 'served_host' => 'tracked.test']);
    webFinding($product, ['host' => 'new6.test', 'checked_gtins' => ['8711000000009']]);
    webFinding($product, ['host' => 'new7.test', 'matched_gtin' => '8711000000009']);

    expect(WebShopFinding::shownFor($product)->pluck('id')->all())->toBe([$shown->id]);
});

it('knows which statuses are unfinished', function (): void {
    expect(WebFindingStatus::unfinished())->toBe([
        WebFindingStatus::New, WebFindingStatus::PendingRead, WebFindingStatus::Read, WebFindingStatus::Checking,
    ]);
});

it('sends open findings the AI turned away back to the first check, and leaves the rest', function (): void {
    $product = Product::factory()->create();
    $rejected = webFinding($product, ['status' => WebFindingStatus::Rejected, 'first_chance' => 0.2, 'generation' => 1]);
    $declined = webFinding($product, ['status' => WebFindingStatus::Declined, 'first_chance' => 0.9, 'second_chance' => 0.1, 'page_title' => 'Coffee beans 500 g', 'checked_at' => now()]);
    $keep = [
        'read cap' => webFinding($product, ['status' => WebFindingStatus::Rejected, 'failure' => 'read_cap']),
        'tracked host' => webFinding($product, ['status' => WebFindingStatus::Rejected, 'failure' => 'tracked_host']),
        'hidden' => webFinding($product, ['status' => WebFindingStatus::Declined, 'dismissed_at' => now()]),
        'Klarna lead' => webFinding($product, ['status' => WebFindingStatus::Declined, 'lead_url' => 'https://www.klarna.com/nl/shopping/pl/1/']),
        'proposed' => webFinding($product),
    ];

    (require database_path('migrations/2026_11_20_120000_recheck_web_findings_turned_away_for_another_pack_size.php'))->up();

    expect($rejected->refresh())->status->toBe(WebFindingStatus::New)->first_chance->toBeNull()->generation->toBe(2)
        ->and($declined->refresh())->status->toBe(WebFindingStatus::New)->second_chance->toBeNull()->page_title->toBeNull()->checked_at->toBeNull();

    foreach ($keep as $finding) {
        expect($finding->refresh()->status)->not->toBe(WebFindingStatus::New);
    }
});
