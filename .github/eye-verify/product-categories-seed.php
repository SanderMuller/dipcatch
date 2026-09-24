<?php declare(strict_types=1);

// Seeds two throwaway accounts for product-categories.mjs: a free account with
// products in two departments, one without a category and one with a long
// title, and a comped Pro account with none. `--teardown` deletes both
// accounts and everything under them. Local only.

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

$freeEmail = 'eye-verify-categories-free@dipcatch.test';
$proEmail = 'eye-verify-categories-pro@dipcatch.test';
$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/categories-eye-verify.json';
$now = CarbonImmutable::now();

$purge = function (User $user): void {
    $user->notifications()->delete();
    Product::query()->where('user_id', $user->id)->get()->each(function (Product $product): void {
        $shopIds = Shop::query()->where('product_id', $product->id)->pluck('id');
        PriceDropEvent::query()->where('product_id', $product->id)->delete();
        ProductCheapestHistory::query()->where('product_id', $product->id)->delete();
        PriceCheck::query()->whereIn('shop_id', $shopIds)->delete();
        Shop::query()->whereIn('id', $shopIds)->delete();
        $product->delete();
    });
};

$existing = User::query()->whereIn('email', [$freeEmail, $proEmail])->get();

if (in_array('--teardown', $argv, true)) {
    $existing->each(function (User $user) use ($purge): void {
        $purge($user);
        $user->delete();
    });

    @unlink($fixturePath);
    echo 'Torn down.' . PHP_EOL;
    exit(0);
}

$account = function (string $email, string $name, array $attributes) use ($existing, $password, $now, $purge): User {
    $user = $existing->firstWhere('email', $email) ?? new User(['email' => $email]);
    $user->forceFill([
        'email' => $email,
        'name' => $name,
        'password' => Hash::make($password),
        'email_verified_at' => $now,
        'is_admin' => false,
        'trial_ends_at' => null,
        'auto_categories' => false,
        ...$attributes,
    ])->save();
    $purge($user);

    return $user;
};

$free = $account($freeEmail, 'Eye Verify Free', []);
$pro = $account($proEmail, 'Eye Verify Pro', ['comped_until' => $now->addYear()]);

$make = function (string $title, ?ProductCategory $category) use ($free): Product {
    $product = Product::factory()->create([
        'user_id' => $free->id,
        'title' => $title,
        'currency' => 'EUR',
        'category' => $category,
        'category_set_by' => $category === null ? null : 'user',
    ]);
    $shop = Shop::factory()->create([
        'product_id' => $product->id,
        'url' => 'https://www.jumbo.com/producten/ev-' . $product->id,
        'currency' => 'EUR',
        'current_price' => '2.49',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '2.49'])->save();

    return $product;
};

$bananas = $make('EV Cat Bananas', ProductCategory::FreshProduce);
$milk = $make('EV Cat Milk', ProductCategory::DairyEggs);
$detergent = $make('EV Cat Detergent', ProductCategory::Laundry);
$loose = $make('EV Cat Loose Item', null);
$long = $make('EV Cat Plant-Based Vegetarian Burger Patties With Extra Long Name Family Pack', ProductCategory::MeatFishVeg);

file_put_contents($fixturePath, json_encode([
    'password' => $password,
    'freeEmail' => $freeEmail,
    'proEmail' => $proEmail,
    'bananasId' => $bananas->id,
    'longId' => $long->id,
    'milkId' => $milk->id,
]));
echo 'Seeded ' . $fixturePath . PHP_EOL;
