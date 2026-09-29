<?php declare(strict_types=1);

// Seeds the throwaway accounts product-markdown.mjs drives, and writes the
// fixture file it reads. `--teardown` deletes the accounts and everything under
// them. It creates accounts with a known password, so it refuses anything but a
// local environment.

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

$owner = 'eye-verify-markdown@dipcatch.test';
$stranger = 'eye-verify-markdown-other@dipcatch.test';
$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/markdown-eye-verify.json';
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
    User::query()->whereIn('email', [$owner, $stranger])->get()->each(function (User $user) use ($purge): void {
        $purge($user);
        $user->delete();
    });

    @unlink($fixturePath);
    echo 'Torn down.' . PHP_EOL;
    exit(0);
}

$account = function (string $email, string $name) use ($password, $now, $purge): User {
    $user = User::query()->where('email', $email)->first() ?? new User(['email' => $email]);
    $user->forceFill([
        'email' => $email,
        'name' => $name,
        'password' => Hash::make($password),
        'email_verified_at' => $now,
        'is_admin' => false,
    ])->save();
    $purge($user);

    return $user;
};

$user = $account($owner, 'Eye Verify Markdown');
$other = $account($stranger, 'Eye Verify Markdown Other');

$product = Product::factory()->create([
    'user_id' => $user->id,
    'title' => 'Beans & more',
    'currency' => 'EUR',
    'target_price' => '5.00',
    'drop_threshold_pct' => '15',
    'share_slug' => str_repeat('m', 32),
]);

$shops = [
    ['url' => 'https://www.jumbo.com/p/ev-beans-500', 'current_price' => '6.00', 'pack_quantity' => 500, 'notes' => 'coupon EVSECRET10'],
    ['url' => 'https://www.ah.nl/p/ev-beans-1000', 'current_price' => '9.00', 'pack_quantity' => 1000],
    ['url' => 'https://www.plus.nl/p/ev-beans?variant=a|b', 'current_price' => '9.50', 'pack_quantity' => 1000],
];

foreach ($shops as $shop) {
    Shop::factory()->create([
        'product_id' => $product->id,
        'currency' => 'EUR',
        'pack_unit' => 'g',
        'current_in_stock' => true,
        'last_checked_at' => $now->subHours(2),
        'last_success_at' => $now->subHours(2),
        ...$shop,
    ]);
}

$product->recomputeCheapestShop();

$foreign = Product::factory()->create(['user_id' => $other->id, 'title' => 'Not yours', 'currency' => 'EUR']);

file_put_contents($fixturePath, json_encode([
    'email' => $owner,
    'password' => $password,
    'productId' => $product->id,
    'slug' => $product->share_slug,
    'foreignProductId' => $foreign->id,
], JSON_PRETTY_PRINT));

echo "Seeded. Fixture at {$fixturePath}" . PHP_EOL;
