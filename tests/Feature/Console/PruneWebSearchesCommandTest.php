<?php declare(strict_types=1);

use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Models\Product;
use App\Models\WebDiscovery;
use App\Models\WebSearch;
use App\Models\WebShopFinding;

function prunableSearch(string $query, int $ageDays): WebSearch
{
    return WebSearch::query()->create(['query_hash' => hash('sha256', $query), 'query' => $query, 'results' => [], 'searched_at' => now()->subDays($ageDays)]);
}

test('it deletes old searches nothing references and keeps the rest', function (): void {
    $unreferenced = prunableSearch('old', 92);
    $young = prunableSearch('young', 90);
    $discovered = prunableSearch('discovered', 200);
    $klarna = prunableSearch('klarna', 200);
    $found = prunableSearch('found', 200);

    $product = Product::factory()->create();
    WebDiscovery::query()->create(['product_id' => $product->id, 'web_search_id' => $discovered->id, 'klarna_search_id' => $klarna->id, 'state' => WebDiscoveryState::Done]);
    WebShopFinding::query()->create(['product_id' => Product::factory()->create()->id, 'web_search_id' => $found->id, 'url' => 'https://a.test/p', 'url_hash' => 'x', 'host' => 'a.test', 'search_title' => 'x', 'status' => WebFindingStatus::Read, 'fingerprint' => 'x']);

    $this->artisan('dipcatch:prune-web-searches')->assertSuccessful();

    expect(WebSearch::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$young, $discovered, $klarna, $found])->pluck('id')->sort()->values()->all())
        ->and(WebDiscovery::query()->sole()->only(['web_search_id', 'klarna_search_id']))->toBe(['web_search_id' => $discovered->id, 'klarna_search_id' => $klarna->id])
        ->and($unreferenced->fresh())->toBeNull();
});

test('it runs daily', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('dipcatch:prune-web-searches');
});
