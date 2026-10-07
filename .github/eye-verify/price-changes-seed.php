<?php declare(strict_types=1);

// Seeds the throwaway Pro account price-changes.mjs drives, and writes the
// fixture file it reads: a dry shampoo whose lowest price dipped once to a
// wrong price that the second reading did not show, plus an alert, a reached
// alert price and a re-check still waiting. `--teardown` deletes the account
// and everything under it. Local only: it sets a known password.

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\LargeDropCheck;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

if (! $app->environment('local')) {
    fwrite(STDERR, 'Refusing to seed: APP_ENV is ' . $app->environment() . ', not local.' . PHP_EOL);
    exit(1);
}

$email = 'eye-verify-price-changes@dipcatch.test';
$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/price-changes-eye-verify.json';
$now = CarbonImmutable::now();

$purge = function (User $user): void {
    Product::query()->where('user_id', $user->id)->get()->each(function (Product $product): void {
        $shopIds = Shop::query()->where('product_id', $product->id)->pluck('id');
        ProductCheapestHistory::query()->where('product_id', $product->id)->delete();
        PriceDropEvent::query()->where('product_id', $product->id)->delete();
        TargetPriceEvent::query()->where('product_id', $product->id)->delete();
        LargeDropCheck::query()->where('product_id', $product->id)->delete();
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
    'name' => 'Eye Verify Price Changes',
    'password' => Hash::make($password),
    'email_verified_at' => $now,
    'is_admin' => false,
    'comped_until' => getenv('FREE') ? null : $now->addYear(),
    'comped_reason' => getenv('FREE') ? null : 'eye-verify',
])->save();
$purge($user);

$product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Andrélon droogshampoo 250 ml', 'currency' => 'EUR', 'target_price' => '5.50']);
$shops = [];
foreach (['koopjesdrogisterij.nl', 'deonlinedrogist.nl', 'ah.nl'] as $host) {
    $shops[$host] = Shop::factory()->create([
        'product_id' => $product->id,
        'url' => "https://www.{$host}/p/ev-andrelon",
        'currency' => 'EUR',
        'current_price' => '6.49',
        'current_in_stock' => true,
    ]);
}

// [shop, price, started hours ago, ended hours ago]
$history = [
    ['ah.nl', '8.99', 24 * 20, 24 * 12],
    ['deonlinedrogist.nl', '7.31', 24 * 12, 24 * 2 + 1],
    ['koopjesdrogisterij.nl', '3.39', 24 * 2 + 1, 24 * 2 + 0.75],
    ['deonlinedrogist.nl', '7.31', 24 * 2 + 0.75, 24],
    ['koopjesdrogisterij.nl', '5.29', 24, 6],
    ['koopjesdrogisterij.nl', '2.49', 6, null],
];

$checks = [];
foreach ($history as $index => [$host, $price, $from, $until]) {
    $check = PriceCheck::factory()->create(['shop_id' => $shops[$host]->id, 'price' => $price, 'checked_at' => $now->subMinutes((int) ($from * 60))]);
    $checks[$index] = $check;

    ProductCheapestHistory::factory()->create([
        'product_id' => $product->id,
        'cheapest_shop_id' => $shops[$host]->id,
        'cheapest_price' => $price,
        'started_at' => $now->subMinutes((int) ($from * 60)),
        'ended_at' => $until === null ? null : $now->subMinutes((int) ($until * 60)),
        'triggering_price_check_id' => $check->id,
    ]);
}

$product->forceFill(['cheapest_shop_id' => $shops['koopjesdrogisterij.nl']->id, 'cheapest_price' => '2.49'])->save();

LargeDropCheck::factory()->rejected()->create(['product_id' => $product->id, 'shop_id' => $shops['koopjesdrogisterij.nl']->id, 'price_check_id' => $checks[2]->id, 'price' => '3.39', 'asked_at' => $now->subHours(49)]);
PriceDropEvent::factory()->create(['product_id' => $product->id, 'price_check_id' => $checks[1]->id, 'fired_at' => $now->subDays(12)]);
TargetPriceEvent::factory()->create(['product_id' => $product->id, 'shop_id' => $shops['koopjesdrogisterij.nl']->id, 'fired_at' => $now->subHours(23)]);
LargeDropCheck::factory()->create(['product_id' => $product->id, 'shop_id' => $shops['koopjesdrogisterij.nl']->id, 'price_check_id' => $checks[5]->id, 'price' => '2.49', 'asked_at' => $now->subHours(6)]);

file_put_contents($fixturePath, json_encode(['email' => $email, 'password' => $password, 'productId' => $product->id], JSON_PRETTY_PRINT));

echo "Seeded. Fixture at {$fixturePath}" . PHP_EOL;
