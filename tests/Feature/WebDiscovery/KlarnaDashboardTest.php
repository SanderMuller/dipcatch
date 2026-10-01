<?php declare(strict_types=1);

use App\Enums\WebFindingStatus;
use App\Livewire\DashboardSuggestedShops;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebShopFinding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.shop_checks.accept_from', 0.7);
    Cache::flush();
    Http::preventStrayRequests();
    seedChains();
});

test('the dashboard row of a Klarna lead in another size names the size and the source', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['title' => 'Hill’s Young Adult Sterilised Eend 10 kg', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://www.zooplus.nl/shop/hills/939943', 'pack_quantity' => '10000.00', 'pack_unit' => 'g']);
    $url = 'https://www.medpets.nl/hills-science-plan-feline-young-adult-sterilised-duck';
    WebShopFinding::query()->forceCreate([
        'product_id' => $product->id,
        'url' => $url,
        'url_hash' => hash('sha256', $url),
        'host' => 'medpets.nl',
        'add_url' => $url . '?sku=MP29294',
        'served_host' => 'medpets.nl',
        'search_title' => 'Hill’s Science Plan Sterilised Cat - Adult - Eend',
        'page_title' => 'Hill’s Science Plan Sterilised Cat - Adult - Eend - 7 kg',
        'page_price' => '58.05',
        'page_currency' => 'EUR',
        'page_pack_quantity' => '7000.00',
        'page_pack_unit' => 'g',
        'read_at' => now(),
        'second_chance' => 0.9,
        'status' => WebFindingStatus::Proposed,
        'fingerprint' => WebShopFinding::fingerprintFor($product->refresh()),
        'lead_url' => 'https://www.klarna.com/nl/shopping/pl/cl456/3202266158/Huisdieren/Hill-s-10kg/',
    ]);
    $this->actingAs($user);

    Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class)
        ->assertSee('Other size: 7 kg — compared per kilo')
        ->assertSee('Found through Klarna');
});
