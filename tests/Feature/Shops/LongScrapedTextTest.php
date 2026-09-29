<?php declare(strict_types=1);

use App\Livewire\Shops\AddShop;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Support\DraftToken;
use App\Mcp\Tools\CreateProductTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\PromotionWindow;
use App\Support\ProductTitle;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * A shop page can hold text of any length; the columns it lands in hold 255
 * characters, and Postgres refuses the insert rather than cutting it.
 */
it('cuts a scraped title to fit, counting characters rather than bytes', function (): void {
    $title = ProductTitle::clean(str_repeat('Ω Lange naam ', 31));

    expect(mb_strlen((string) $title))->toBeLessThanOrEqual(255)
        ->and($title)->toStartWith('Ω Lange naam');
});

it('creates a product through the assistant from a page with a very long title', function (): void {
    $me = User::factory()->create();
    $draft = DraftToken::issue(
        $me,
        ['title' => ProductTitle::clean(str_repeat('Ω Lange naam ', 31)), 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true],
        'https://ah.nl/p/coffee',
        'ah',
        variantKey: null,
    );

    DipCatchServer::actingAs($me)->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true])->assertOk();

    expect(mb_strlen((string) $me->products()->sole()->title))->toBeLessThanOrEqual(255);
});

it('cuts a promotion label to fit', function (): void {
    $window = PromotionWindow::make(CarbonImmutable::now()->addDay(), label: str_repeat('Mega actie! ', 40));

    expect(mb_strlen((string) $window?->label))->toBeLessThanOrEqual(255);
});

it('stores a variant key longer than 255 characters', function (): void {
    $shop = Shop::factory()->create(['variant_key' => 'https://shop.example.com/p/1?variant=' . str_repeat('9', 300)]);

    expect(mb_strlen((string) $shop->fresh()?->variant_key))->toBeGreaterThan(255);
});

it('refuses a manual selector longer than the column holds', function (): void {
    $product = Product::factory()->create();
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->set('priceSelector', '.price' . str_repeat(' span', 60))
        ->call('probeWithSelectors')
        ->assertSet('errorCode', 'user_selector_too_long');
});
