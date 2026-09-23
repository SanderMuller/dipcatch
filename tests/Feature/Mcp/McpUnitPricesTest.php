<?php declare(strict_types=1);

use App\Actions\Shops\ProbeOutcome;
use App\Enums\ProbeFailure;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Support\ProbeReporter;
use App\Mcp\Support\ProductPresenter;
use App\Mcp\Tools\ListProductsTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function mcpTablets(User $user): Product
{
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'title' => 'Tablets']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/' . $product->id, 'current_price' => '12.99', 'pack_quantity' => '400.00', 'pack_unit' => 'piece']);
    Shop::factory()->for($product)->create(['url' => 'https://kruidvat.nl/p/' . $product->id, 'current_price' => '21.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece']);
    $product->recomputeCheapestShop();

    return $product->refresh();
}

it('states the figure the app leads with and keeps every existing field', function (): void {
    $summary = new ProductPresenter()->summary(mcpTablets(User::factory()->create()));

    expect($summary)
        ->toMatchArray([
            'headline_price' => '0.0275',
            'headline_unit' => 'piece',
            'headline_price_basis' => 'unit',
            // Unchanged: an assistant that read these before still can.
            'cheapest_price' => '12.99',
            'best_value_price' => '21.99',
            'best_value_unit_price' => '0.0275',
            'comparison_unit' => 'piece',
            'shop_count' => 2,
        ]);
});

it('leads with the pack price for a product that compares no unit', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['current_price' => '299.00', 'pack_quantity' => null, 'pack_unit' => null]);
    $product->recomputeCheapestShop();

    expect(new ProductPresenter()->summary($product->refresh()))
        ->toMatchArray(['headline_price' => '299.00', 'headline_unit' => null, 'headline_price_basis' => 'pack']);
});

it('lists products without a query per product', function (): void {
    $queriesFor = function (int $count): int {
        $user = User::factory()->create();

        foreach (range(1, $count) as $index) {
            mcpTablets($user);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        DipCatchServer::actingAs($user)->tool(ListProductsTool::class)->assertOk()->assertSee('"headline_price":"0.0275"');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($queriesFor(4))->toBe($queriesFor(1));
});

it('states the unit price a probed page works out to', function (): void {
    $preview = new ProbeReporter()->preview(
        ['title' => 'Vitamine D3 tabletten', 'price' => '21.99', 'currency' => 'EUR', 'pack_size' => '800 stuks'],
        ProbeOutcome::failed(ProbeFailure::cases()[0]),
    );

    expect($preview)->toMatchArray(['unit_price' => '0.0275', 'comparison_unit' => 'piece']);

    $unsized = new ProbeReporter()->preview(['title' => 'Camera', 'price' => '299.00', 'currency' => 'EUR'], ProbeOutcome::failed(ProbeFailure::cases()[0]));

    expect($unsized)->toMatchArray(['unit_price' => null, 'comparison_unit' => null]);
});
