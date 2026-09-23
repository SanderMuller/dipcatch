<?php declare(strict_types=1);

use App\Enums\ConsumerPriceIssue;
use App\Enums\PackProvenance;
use App\Enums\ShopHealth;
use App\Livewire\Dashboard;
use App\Livewire\Products\ProductList;
use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

/**
 * Shops named by host. `Shop::booted()` derives the host from the URL, so
 * the URL is what decides which shop a test is naming.
 *
 * @param  array<string, array<string, mixed>>  $shops  host => attributes
 */
function productWithShops(array $shops): Product
{
    $product = Product::factory()->create(['currency' => 'EUR']);

    foreach ($shops as $host => $attributes) {
        $shop = Shop::factory()->for($product)->create([
            'url' => 'https://' . $host . '/p/' . bin2hex(random_bytes(4)),
        ]);

        $shop->forceFill(['currency' => 'EUR', ...$attributes])->save();
    }

    return $product->refresh();
}

test('the best value is the lowest price per unit, not the lowest price', function (): void {
    // The Lay's case: a 370 g bag at 1.99 beats a 200 g bag at 1.69 per kilo.
    $product = productWithShops([
        'ah.nl' => ['current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g'],
        'lidl.nl' => ['current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('lidl.nl')
        ->and($product->bestValueShop()?->unitPrice())->toBe('5.3784');
});

test('a shop with no pack size cannot be the best value', function (): void {
    $product = productWithShops([
        'boodschaapje.nl' => ['current_price' => '0.99'],
        'lidl.nl' => ['current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('lidl.nl');
});

test('units that cannot be compared are not compared', function (): void {
    // Two shops price per kilo, one per piece. EUR/kg and EUR/piece are not
    // the same measure, so the larger group decides.
    $product = productWithShops([
        'a.test' => ['current_price' => '1.00', 'pack_quantity' => '1.00', 'pack_unit' => 'piece'],
        'b.test' => ['current_price' => '2.00', 'pack_quantity' => '200.00', 'pack_unit' => 'g'],
        'c.test' => ['current_price' => '2.50', 'pack_quantity' => '400.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('c.test');
});

test('paused, dead and out-of-stock shops are left out', function (): void {
    $product = productWithShops([
        'cheap-but-paused.test' => ['current_price' => '1.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'active' => false],
        'cheap-but-dead.test' => ['current_price' => '1.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'health' => ShopHealth::Dead],
        'cheap-but-gone.test' => ['current_price' => '1.00', 'pack_quantity' => '500.00', 'pack_unit' => 'g', 'current_in_stock' => false],
        'lidl.nl' => ['current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('lidl.nl');
});

test('a cheapest shop with no pack size shows no unit price beside it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    $sizeless = Shop::factory()->for($product)->create([
        'url' => 'https://dataset.test/p/1', 'currency' => 'EUR', 'current_price' => '0.99',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/lay-s/p2', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $sizeless->id, 'cheapest_price' => '0.99'])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSeeText('€0.99')
        // The best value still stands on its own.
        ->assertSeeText('€5.38 /kg');
});

test('a product whose shops state no size has no best value', function (): void {
    $product = productWithShops(['a.test' => ['current_price' => '1.00']]);

    expect($product->bestValueShop())->toBeNull();
});

test('the product page leads with the best value and notes the lowest price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    $ah = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi1/x', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/lay-s/p1', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $ah->id, 'cheapest_price' => '1.69'])->save();

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertDontSeeText('Best price now')
        ->assertSeeTextInOrder(['Best value', '€5.38 /kg', '€1.99 for 370 g', 'lidl.nl'])
        // The lowest pack price is a note under it, with the gap per kilo.
        ->assertSeeText('Lowest price: €1.69 for 200 g at')
        ->assertSeeText('That is 57% more per kilo than the best value.')
        // Both stated per kilo in the shops table, so the gap can be read off.
        ->assertSeeText('€8.45 /kg');
});

test('the products list shows the best value beside the cheapest price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => "Lay's Naturel"]);

    $ah = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi9/x', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/lay-s/p9', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $ah->id, 'cheapest_price' => '1.69'])->save();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSeeText('€1.69')
        ->assertSeeText('€8.45 /kg')
        ->assertSeeText('€5.38 /kg')
        // Named, because the best value is usually not the cheapest shop.
        ->assertSeeText('lidl.nl');
});

test('the list reads every shop once, not once per product row', function (): void {
    $render = function (int $products): int {
        $user = User::factory()->create();

        foreach (range(1, $products) as $i) {
            $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
            Shop::factory()->for($product)->create([
                'url' => "https://shop{$i}.test/p/1", 'currency' => 'EUR', 'current_price' => '1.99',
                'pack_quantity' => '370.00', 'pack_unit' => 'g',
            ]);
        }

        $this->actingAs($user);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        livewire(ProductList::class)->assertSeeText('€5.38 /kg');

        return $queries;
    };

    // Flatness is the property worth guarding, so the test compares two
    // sizes rather than trusting a threshold: a fixed cost may be added to
    // the page, but reading shops per row would cost eight more here.
    expect($render(4))->toBe($render(12));
});

test('the dashboard says how long the drop price lasts', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'last_notified_price' => '1.79',
        'last_notified_at' => now()->subHour(),
    ]);

    $ah = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi6/x', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    $ah->forceFill(['promotion_ends_at' => CarbonImmutable::parse('2036-09-06 21:59:59')])->save();
    $product->forceFill(['cheapest_shop_id' => $ah->id, 'cheapest_price' => '1.69'])->save();

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSeeText('€1.69')
        // The shop has its own column, so the price only needs the deadline.
        ->assertSeeText('until 6 Sep');
});

test('the dashboard card leads with the best value and links its shop', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'title' => "Lay's Naturel",
        'last_notified_price' => '1.79',
        'last_notified_at' => now()->subHour(),
    ]);

    $ah = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi7/x', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/lay-s/p7', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $ah->id, 'cheapest_price' => '1.69'])->save();

    $this->actingAs($user);

    // The figure is the best value, and the shop link beneath it names the
    // shop that sells it. The comparison with the lowest pack price stays on
    // the product list and the product page.
    livewire(Dashboard::class)
        ->assertSeeTextInOrder(['€5.38 /kg', '€1.99 for 370 g', 'lidl.nl'])
        ->assertDontSeeText('Lowest price')
        ->assertDontSeeText('ah.nl')
        ->assertSeeHtml('href="https://lidl.nl/p/lay-s/p7"')
        ->assertDontSeeHtml('href="https://ah.nl/producten/product/wi7/x"');
});

test('the list says how long a quoted price lasts', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => "Lay's Naturel"]);

    $ah = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi5/x', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    $ah->forceFill(['promotion_ends_at' => CarbonImmutable::parse('2036-09-06 21:59:59')])->save();

    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/lay-s/p5', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $ah->id, 'cheapest_price' => '1.69'])->save();

    $this->actingAs($user);

    livewire(ProductList::class)
        // The cheapest price is a bonus that runs out; the best value is not.
        ->assertSeeText('ah.nl · until 6 Sep')
        ->assertSeeText('lidl.nl')
        ->assertDontSeeText('lidl.nl · until');
});

test('a shop with no promotion is named without a deadline', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/producten/x', 'currency' => 'EUR', 'current_price' => '2.19',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '2.19'])->save();

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSeeText('jumbo.com')
        ->assertDontSeeText('jumbo.com ·');
});

test('two shops a rounding step apart are ranked on the unrounded figure', function (): void {
    // The Roter vitamin C case, reported from production: a 400-tablet pack at
    // 12.99 is 0.032475 a tablet and an 800-tablet pack at 21.99 is 0.0274875.
    // Both print as 0.03. Ranked on that string the older row kept the crown,
    // and it is 18% the dearer tablet.
    $product = productWithShops([
        'ah.nl' => ['current_price' => '12.99', 'pack_quantity' => '400.00', 'pack_unit' => 'piece'],
        'benushop.nl' => ['current_price' => '21.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('benushop.nl');
});

test('the page shows a cheap per-piece price at the precision that separates it', function (): void {
    // Both packs used to render EUR 0.03 — the field a shopper compares on
    // could not show an 18% difference.
    $product = productWithShops([
        'ah.nl' => ['current_price' => '12.99', 'pack_quantity' => '400.00', 'pack_unit' => 'piece'],
        'benushop.nl' => ['current_price' => '21.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece'],
    ]);

    $this->actingAs($product->user ?? User::factory()->create());

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSeeText('€0.0325')
        ->assertSeeText('€0.0275');
});

test('a price quoted without VAT takes neither answer', function (): void {
    // The fivestartrading case: 90 cups at 21.15 ex-VAT undercuts a real
    // 22.00 at Amazon by 4%, and took both the lowest price and the best
    // value on a figure nobody can pay.
    $product = productWithShops([
        'amazon.nl' => ['current_price' => '22.00', 'pack_quantity' => '90.00', 'pack_unit' => 'piece'],
        'fivestartrading-holland.eu' => [
            'current_price' => '21.15', 'pack_quantity' => '90.00', 'pack_unit' => 'piece',
            'consumer_price_issue' => ConsumerPriceIssue::ExcludesVat, 'consumer_price_note' => 'excl. btw',
        ],
    ]);

    $product->recomputeCheapestShop();
    $product->refresh();

    expect($product->bestValueShop()?->host)->toBe('amazon.nl')
        ->and($product->lowestOutlayShop()?->host)->toBe('amazon.nl');
});

test('a shop out for VAT does not decide the comparison unit', function (): void {
    // Two ex-VAT rows measured in pieces must not make the one live gram row
    // "measured in a different unit" and leave the product with no winner.
    $product = productWithShops([
        'a.test' => ['current_price' => '1.00', 'pack_quantity' => '10.00', 'pack_unit' => 'piece', 'consumer_price_issue' => ConsumerPriceIssue::ExcludesVat],
        'b.test' => ['current_price' => '1.00', 'pack_quantity' => '10.00', 'pack_unit' => 'piece', 'consumer_price_issue' => ConsumerPriceIssue::ExcludesVat],
        'c.test' => ['current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g'],
    ]);

    expect($product->bestValueShop()?->host)->toBe('c.test');
});

test('the product page says why a VAT-exclusive shop is out', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    foreach ([
        ['amazon.nl', '22.00', false],
        ['fivestartrading-holland.eu', '21.15', true],
    ] as [$host, $price, $exVat]) {
        Shop::factory()->for($product)->create(['url' => 'https://' . $host . '/p/1'])
            ->forceFill([
                'currency' => 'EUR', 'current_price' => $price,
                'pack_quantity' => '90.00', 'pack_unit' => 'piece',
                'consumer_price_issue' => $exVat ? ConsumerPriceIssue::ExcludesVat : null,
                'consumer_price_note' => $exVat ? 'excl. btw' : null,
            ])->save();
    }

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product->refresh()])
        ->assertSeeText('Price excludes VAT — not comparable');
});

test('a trade-only price takes neither answer either', function (): void {
    // The Prometeus case: 12.99 undercut two real consumer shops at 14.99.
    $product = productWithShops([
        'prometeus.nl' => [
            'current_price' => '12.99', 'pack_quantity' => '550.00', 'pack_unit' => 'g',
            'consumer_price_issue' => ConsumerPriceIssue::TradeOnly,
            'consumer_price_note' => 'sign in to see prices',
        ],
        'bodyandfit.com' => ['current_price' => '14.99', 'pack_quantity' => '550.00', 'pack_unit' => 'g'],
    ]);

    $product->recomputeCheapestShop();
    $product->refresh();

    expect($product->bestValueShop()?->host)->toBe('bodyandfit.com')
        ->and($product->lowestOutlayShop()?->host)->toBe('bodyandfit.com');
});

test('an inherited size that reads far dearer than the field is refused', function (): void {
    // Reported 2026-09-22. A 150-tablet pack whose own size could not be read
    // inherited a sibling's 75 and showed 0.1265 a tablet against a true
    // 0.0633 — the best deal on the product, displayed as the worst. The guard
    // used to refuse only the implausibly cheap, because an inferred row
    // cannot win. Nobody needed it to win in order to be misled by it.
    $product = productWithShops([
        'internetdrogisterij.nl' => ['current_price' => '4.75', 'pack_quantity' => '75.00', 'pack_unit' => 'piece'],
        'koopjesdrogisterij.nl' => ['current_price' => '4.75', 'pack_quantity' => '75.00', 'pack_unit' => 'piece'],
        // States no size of its own, and is really a 150-pack.
        'deonlinedrogist.nl' => ['current_price' => '9.49'],
    ]);

    $packs = $product->comparablePacks();
    $borrowed = $product->shops->where('host', 'deonlinedrogist.nl')->sole();

    expect($packs->for($borrowed)?->reason())->toBe('Pack size looks wrong for this product')
        ->and($packs->unitPriceOf($borrowed))->toBeNull();
});

test('an inherited size close to the field is still used', function (): void {
    // The guard refuses a figure far off the field, not any figure at all.
    $product = productWithShops([
        'a.test' => ['current_price' => '4.75', 'pack_quantity' => '75.00', 'pack_unit' => 'piece'],
        'b.test' => ['current_price' => '4.75', 'pack_quantity' => '75.00', 'pack_unit' => 'piece'],
        'c.test' => ['current_price' => '5.25'],
    ]);

    $packs = $product->comparablePacks();
    $borrowed = $product->shops->where('host', 'c.test')->sole();

    expect($packs->for($borrowed)?->provenance)->toBe(PackProvenance::Inferred)
        ->and($packs->unitPriceOf($borrowed))->toBe('0.0700');
});
