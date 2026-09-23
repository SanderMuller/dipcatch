<?php declare(strict_types=1);

// Adds the edge cases of the unit-price headline to the account that
// product-cards-seed.php made: a shop sized in another unit, a shop with no
// size of its own, a trade-only price, a live bundle on the best value, and a
// drop alerted on pack prices. Run product-cards-seed.php first; its
// `--teardown` removes everything this adds. Local only.

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\ConsumerPriceIssue;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;

if (! $app->environment('local')) {
    fwrite(STDERR, 'Refusing to seed: APP_ENV is ' . $app->environment() . ', not local.' . PHP_EOL);
    exit(1);
}

$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/tiles-eye-verify.json';
$fixture = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);

$crisps = Product::query()->findOrFail($fixture['crispsId']);
$tablets = Product::query()->findOrFail($fixture['tabletsId']);

$shop = function (Product $product, string $url, array $attributes): Shop {
    return Shop::factory()->create([
        'product_id' => $product->id,
        'currency' => 'EUR',
        'url' => $url . '/ev-' . $product->id,
        ...$attributes,
    ]);
};

// Crisps compare per kilo. A box of twelve is sold by the piece, so it is left
// out of the comparison; it is also the lowest pack price, so the note names it
// without a percentage.
$shop($crisps, 'https://fitnesscandy.nl/p/crisps', ['current_price' => '1.49', 'pack_quantity' => '12.00', 'pack_unit' => 'piece']);
$crisps->refresh()->recomputeCheapestShop();

// Tablets compare per piece. A trade-only price is the lowest per piece and
// must win nothing. The best value runs a two-for deal.
$shop($tablets, 'https://wholesale.test/p/tablets', ['current_price' => '9.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece', 'consumer_price_issue' => ConsumerPriceIssue::TradeOnly]);
Shop::query()->where('product_id', $tablets->id)->where('pack_quantity', '800.00')->where('url', 'like', 'https://kruidvat.nl%')->update([
    'single_item_price' => '23.99', 'bundle_quantity' => 2, 'bundle_total_price' => '43.98',
]);
$tablets->refresh()->recomputeCheapestShop();

// An alert fired on pack prices, before the product compared per piece.
$tablets->forceFill(['last_notified_price' => '12.99', 'last_notified_at' => now()])->save();
PriceDropEvent::factory()->create([
    'price_check_id' => PriceCheck::factory()->create(['shop_id' => $tablets->cheapest_shop_id])->id,
    'user_id' => $tablets->user_id,
    'product_id' => $tablets->id,
    'currency' => 'EUR',
    'reference_price' => '15.99',
    'new_price' => '12.99',
    'drop_pct' => 18.8,
    'comparison_unit' => null,
    'fired_at' => now(),
]);

// A product whose sized shops agree, plus one that states nothing: the
// silent one inherits the size and is marked estimated.
$pesto = Product::factory()->create(['user_id' => $crisps->user_id, 'title' => 'EV Estimated Pesto', 'currency' => 'EUR', 'created_at' => now()->subMinutes(6)]);
$shop($pesto, 'https://ah.nl/producten/pesto', ['current_price' => '2.49', 'pack_quantity' => '190.00', 'pack_unit' => 'g']);
$shop($pesto, 'https://jumbo.com/producten/pesto', ['current_price' => '2.69', 'pack_quantity' => '190.00', 'pack_unit' => 'g']);
$shop($pesto, 'https://plus.nl/p/pesto', ['current_price' => '2.59', 'pack_quantity' => null, 'pack_unit' => null]);
$pesto->refresh()->recomputeCheapestShop();

file_put_contents($fixturePath, json_encode([...$fixture, 'pestoId' => $pesto->id]));
echo json_encode(['crisps' => $crisps->id, 'tablets' => $tablets->id, 'pesto' => $pesto->id]) . PHP_EOL;
