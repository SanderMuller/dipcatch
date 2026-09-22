<?php declare(strict_types=1);

// Seeds the throwaway account product-cards.mjs drives, and writes the fixture
// file it reads. `--teardown` deletes the account and everything under it.
// It creates an account with a known password, so it refuses anything but a
// local environment.

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\ProductCategory;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

if (! $app->environment('local')) {
    fwrite(STDERR, 'Refusing to seed: APP_ENV is ' . $app->environment() . ', not local.' . PHP_EOL);
    exit(1);
}

$email = 'eye-verify-cards@dipcatch.test';
$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/tiles-eye-verify.json';
$now = CarbonImmutable::now();

$purge = function (User $user): void {
    Product::query()->where('user_id', $user->id)->get()->each(function (Product $product): void {
        $shopIds = Shop::query()->where('product_id', $product->id)->pluck('id');
        PriceDropEvent::query()->where('product_id', $product->id)->delete();
        ProductCheapestHistory::query()->where('product_id', $product->id)->delete();
        PriceCheck::query()->whereIn('shop_id', $shopIds)->delete();
        Shop::query()->whereIn('id', $shopIds)->delete();
        $product->delete();
    });
};

$existing = User::query()->where('email', $email)->first();

if (in_array('--teardown', $argv, true)) {
    if ($existing !== null) {
        $purge($existing);
        $existing->delete();
    }

    @unlink($fixturePath);
    echo 'Torn down.' . PHP_EOL;
    exit(0);
}

$user = $existing ?? new User(['email' => $email]);
$user->forceFill([
    'email' => $email,
    'name' => 'Eye Verify Cards',
    'password' => Hash::make($password),
    'email_verified_at' => $now,
    'is_admin' => false,
    'trial_ends_at' => $now->addDays(30),
])->save();
$purge($user);

$image = fn (string $seed): string => "https://picsum.photos/seed/{$seed}/600/450";

$make = function (string $title, array $shops, array $attributes, int $ageMinutes) use ($user, $now): Product {
    $product = Product::factory()->create(array_merge([
        'user_id' => $user->id,
        'title' => $title,
        'currency' => 'EUR',
        'created_at' => $now->subMinutes($ageMinutes),
    ], $attributes));

    $made = [];
    foreach ($shops as $i => $shop) {
        $made[] = Shop::factory()->create(array_merge(['product_id' => $product->id, 'currency' => 'EUR'], $shop, [
            'url' => $shop['url'] . '/ev-' . $product->id . '-' . $i,
        ]));
    }

    if ($made !== []) {
        $cheapest = collect($made)->sortBy(fn (Shop $shop): float => (float) $shop->current_price)->first();
        $product->forceFill(['cheapest_shop_id' => $cheapest->id, 'cheapest_price' => $cheapest->current_price])->save();
    }

    return $product->refresh();
};

// The event factory makes a shop of its own for its price check unless it is
// handed one, which would add a stray shop to the card.
$drop = function (Product $product, array $attributes) use ($user): void {
    PriceDropEvent::factory()->create(array_merge([
        'price_check_id' => PriceCheck::factory()->create(['shop_id' => $product->cheapest_shop_id])->id,
        'user_id' => $user->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ], $attributes));
};

$oil = $make('EV Drop Olive Oil', [
    ['url' => 'https://bol.com/p/oil', 'current_price' => '11.95'],
    ['url' => 'https://ah.nl/producten/oil', 'current_price' => '12.49', 'promotion_ends_at' => $now->addDays(5)],
    ['url' => 'https://jumbo.com/producten/oil', 'current_price' => '12.99'],
    ['url' => 'https://dirk.nl/producten/oil', 'current_price' => '13.50'],
], ['image_url' => $image('oil'), 'category' => ProductCategory::CoffeeTea, 'last_notified_price' => '11.95', 'last_notified_at' => $now], 1);
$drop($oil, ['reference_price' => '14.10', 'new_price' => '11.95', 'drop_pct' => 15.25]);

$crisps = $make('EV Unit Drop Crisps', [
    ['url' => 'https://ah.nl/producten/crisps', 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g'],
    ['url' => 'https://lidl.nl/p/crisps', 'current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g'],
], ['image_url' => $image('crisps'), 'last_notified_price' => '1.69', 'last_notified_at' => $now], 2);
// Two months of the 370 g bag, so the usual and the lowest price per kilo
// can be read from history.
$previous = null;
foreach ([['2.19', 60], ['1.99', 45], ['2.09', 30], ['1.89', 15]] as [$price, $daysAgo]) {
    $segment = ProductCheapestHistory::query()->create([
        'product_id' => $crisps->id, 'cheapest_shop_id' => $crisps->cheapest_shop_id, 'cheapest_price' => $price,
        'pack_quantity' => '370.00', 'pack_unit' => 'g', 'started_at' => $now->subDays($daysAgo), 'ended_at' => null,
    ]);
    $previous?->update(['ended_at' => $now->subDays($daysAgo)]);
    $previous = $segment;
}
$drop($crisps, ['reference_price' => null, 'reference_unit_price' => '9.9900', 'comparison_unit' => 'g', 'new_price' => '1.69', 'drop_pct' => 20.0]);

$make('EV Paused Soap', [
    ['url' => 'https://ah.nl/producten/soap', 'current_price' => '4.79'],
    ['url' => 'https://jumbo.com/producten/soap', 'current_price' => '4.95'],
], ['image_url' => $image('soap'), 'active' => false], 3);

$pausedDrop = $make('EV Paused Drop Coffee', [
    ['url' => 'https://ah.nl/producten/coffee', 'current_price' => '6.79'],
], ['image_url' => $image('coffee'), 'active' => false, 'last_notified_price' => '6.79', 'last_notified_at' => $now], 4);
$drop($pausedDrop, ['reference_price' => '7.99', 'new_price' => '6.79', 'drop_pct' => 15.0]);

$make('EV No Price Thing', [], ['image_url' => null], 5);

// Spread over two departments, so the category list has groups to open.
$fillerCategories = [ProductCategory::DairyEggs, ProductCategory::Laundry, ProductCategory::Cleaning, null];

for ($i = 1; $i <= 31; $i++) {
    $make(sprintf('EV Filler %02d', $i), [
        ['url' => 'https://jumbo.com/producten/filler', 'current_price' => (string) (1 + $i)],
    ], ['image_url' => $image('filler' . $i), 'category' => $fillerCategories[$i % 4]], 10 + $i);
}

file_put_contents($fixturePath, json_encode(['email' => $email, 'password' => $password]));
echo json_encode(['email' => $email, 'products' => Product::query()->where('user_id', $user->id)->count(), 'fixture' => $fixturePath]) . PHP_EOL;
