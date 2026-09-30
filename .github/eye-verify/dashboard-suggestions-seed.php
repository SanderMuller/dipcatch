<?php declare(strict_types=1);

// Seeds the throwaway account dashboard-suggestions.mjs drives: a Pro account
// with the AI shop check, products the daily dataset has rows for, and one
// proposed web finding. `--teardown` deletes the account and everything under
// it. It creates an account with a known password, so it refuses anything but
// a local environment.

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\WebFindingStatus;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebShopFinding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

if (! $app->environment('local')) {
    fwrite(STDERR, 'Refusing to seed: APP_ENV is ' . $app->environment() . ', not local.' . PHP_EOL);
    exit(1);
}

$email = 'eye-verify-dashboard-suggestions@dipcatch.test';
$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/dashboard-suggestions-eye-verify.json';
$now = CarbonImmutable::now();
$existing = User::query()->where('email', $email)->first();

if (in_array('--teardown', $argv, true)) {
    if ($existing !== null) {
        Product::query()->where('user_id', $existing->id)->get()->each(fn (Product $product) => $product->delete());
        $existing->delete();
    }

    @unlink($fixturePath);
    echo 'Torn down.' . PHP_EOL;
    exit(0);
}

$user = $existing ?? new User(['email' => $email]);
$user->forceFill([
    'email' => $email,
    'name' => 'Eye Verify Dashboard Suggestions',
    'password' => Hash::make($password),
    'email_verified_at' => $now,
    'is_admin' => false,
    'trial_ends_at' => $now->addDays(30),
    'shop_checks' => true,
])->save();
Product::query()->where('user_id', $user->id)->get()->each(fn (Product $product) => $product->delete());

$track = static function (string $title, string $price) use ($user): Product {
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => $title, 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://www.eye-verify-shop.test/p/' . md5($title), 'host' => 'eye-verify-shop.test', 'current_price' => $price, 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();
    // No paid Jev checks from a verification run.
    Cache::put("shop-suggestions:verify:{$product->id}", true, now()->addHour());

    return $product;
};

foreach (['Maggi Jus pikant' => '2.19', 'Knorr Jus' => '1.99', 'Bonduelle Broccoliroosjes' => '1.49', 'Le Rustique Camembert' => '3.29'] as $title => $price) {
    $track($title, $price);
}

$coffee = $track('Douwe Egberts Aroma Rood koffiebonen 1 kg', '18.99');
WebShopFinding::query()->create([
    'product_id' => $coffee->id,
    'url' => 'https://koffiehenk.nl/douwe-egberts-aroma-rood-bonen',
    'url_hash' => hash('sha256', 'eye-verify-dashboard-koffiehenk'),
    'host' => 'koffiehenk.nl',
    'add_url' => 'https://koffiehenk.nl/douwe-egberts-aroma-rood-bonen',
    'served_host' => 'koffiehenk.nl',
    'search_title' => 'Douwe Egberts Aroma Rood 1 kilo bonen',
    'page_title' => 'Douwe Egberts Aroma Rood 1 kilo bonen',
    'page_price' => '17.49',
    'page_currency' => 'EUR',
    'read_at' => $now,
    'second_chance' => 0.99,
    'status' => WebFindingStatus::Proposed,
    'fingerprint' => WebShopFinding::fingerprintFor($coffee->load('shops')),
]);

file_put_contents($fixturePath, json_encode(['email' => $email, 'password' => $password, 'coffeeId' => $coffee->id]));
echo json_encode(['email' => $email, 'fixture' => $fixturePath, 'pro' => $user->refresh()->wantsShopChecks()]) . PHP_EOL;
