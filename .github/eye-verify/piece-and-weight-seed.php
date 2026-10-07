<?php declare(strict_types=1);

// Seeds the throwaway account piece-and-weight.mjs drives, and writes the
// fixture file it reads: the Iglo fish fingers field, which compares per kilo
// once the shops' second sizes count, and a product whose per-kilo target is
// paused because every shop now counts pieces. `--teardown` deletes the
// account and everything under it. Local only: it sets a known password.

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

if (! $app->environment('local')) {
    fwrite(STDERR, 'Refusing to seed: APP_ENV is ' . $app->environment() . ', not local.' . PHP_EOL);
    exit(1);
}

$email = 'eye-verify-piece-weight@dipcatch.test';
$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/piece-weight-eye-verify.json';
$now = CarbonImmutable::now();

$purge = function (User $user): void {
    Product::query()->where('user_id', $user->id)->get()->each(function (Product $product): void {
        $shopIds = Shop::query()->where('product_id', $product->id)->pluck('id');
        PriceCheck::query()->whereIn('shop_id', $shopIds)->delete();
        Shop::query()->whereIn('id', $shopIds)->delete();
        $product->delete();
    });
};

if (in_array('--teardown', $argv, true)) {
    User::query()->where('email', $email)->get()->each(function (User $user) use ($purge): void {
        $purge($user);
        $user->delete();
    });

    @unlink($fixturePath);
    echo 'Torn down.' . PHP_EOL;
    exit(0);
}

$user = User::query()->where('email', $email)->first() ?? new User(['email' => $email]);
$user->forceFill([
    'email' => $email,
    'name' => 'Eye Verify Piece Weight',
    'password' => Hash::make($password),
    'email_verified_at' => $now,
    'is_admin' => false,
])->save();
$purge($user);

$shop = function (Product $product, string $url, string $price, string $quantity, string $unit, ?string $altQuantity = null, ?string $altUnit = null) use ($now): void {
    Shop::factory()->create([
        'product_id' => $product->id,
        'url' => $url,
        'currency' => 'EUR',
        'current_price' => $price,
        'current_in_stock' => true,
        'pack_quantity' => $quantity,
        'pack_unit' => $unit,
        'alt_pack_quantity' => $altQuantity,
        'alt_pack_unit' => $altUnit,
        'alt_pack_since' => $altQuantity === null ? null : $now,
        'last_checked_at' => $now->subMinutes(5),
        'last_success_at' => $now->subMinutes(5),
    ]);
};

$iglo = Product::factory()->create(['user_id' => $user->id, 'title' => 'Iglo Vissticks', 'currency' => 'EUR', 'unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece']);
$shop($iglo, 'https://www.ah.nl/producten/product/wi191096/ev-iglo', '4.99', '20.00', 'piece', '560.00', 'g');
$shop($iglo, 'https://www.ah.nl/producten/product/wi445472/ev-iglo', '6.59', '30.00', 'piece', '840.00', 'g');
$shop($iglo, 'https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/305527?ev', '3.75', '20.00', 'piece', '560.00', 'g');
$shop($iglo, 'https://www.jumbo.com/producten/ev-iglo-15-stuks', '3.69', '420.00', 'g', '15.00', 'piece');
$shop($iglo, 'https://www.dirk.nl/boodschappen/ev-iglo/4944', '3.99', '560.00', 'g', '20.00', 'piece');
$shop($iglo, 'https://www.jumbo.com/producten/ev-iglo-xxl', '6.15', '840.00', 'g');
$iglo->recomputeCheapestShop();

$paused = Product::factory()->create(['user_id' => $user->id, 'title' => 'Vaatwastabletten', 'currency' => 'EUR', 'unit_price_target' => '5.50', 'unit_price_target_unit' => 'g']);
$shop($paused, 'https://www.ah.nl/producten/product/wi1/ev-tabs', '9.99', '18.00', 'piece');
$shop($paused, 'https://www.kruidvat.nl/ev-tabs', '19.99', '40.00', 'piece');
$paused->recomputeCheapestShop();

file_put_contents($fixturePath, json_encode([
    'email' => $email,
    'password' => $password,
    'igloId' => $iglo->id,
    'pausedId' => $paused->id,
], JSON_PRETTY_PRINT));

echo "Seeded. Fixture at {$fixturePath}" . PHP_EOL;
