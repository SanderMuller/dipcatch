<?php declare(strict_types=1);

// Seeds the throwaway account web-suggestions.mjs drives, and writes the
// fixture file it reads. `--propose` stores a proposed web finding and marks
// discovery done, as the jobs would. `--teardown` deletes the account and
// everything under it. It creates an account with a known password, so it
// refuses anything but a local environment.

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebShopFinding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

if (! $app->environment('local')) {
    fwrite(STDERR, 'Refusing to seed: APP_ENV is ' . $app->environment() . ', not local.' . PHP_EOL);
    exit(1);
}

$email = 'eye-verify-web-suggestions@dipcatch.test';
$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/web-suggestions-eye-verify.json';
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

if (in_array('--propose', $argv, true)) {
    $product = Product::query()->where('user_id', $existing?->id)->with('shops')->firstOrFail();

    WebShopFinding::query()->create([
        'product_id' => $product->id,
        'url' => 'https://koffiehenk.nl/douwe-egberts-aroma-rood-bonen',
        'url_hash' => hash('sha256', 'eye-verify-koffiehenk'),
        'host' => 'koffiehenk.nl',
        'add_url' => 'https://koffiehenk.nl/douwe-egberts-aroma-rood-bonen',
        'served_host' => 'koffiehenk.nl',
        'search_title' => 'Douwe Egberts Aroma Rood 1 kilo bonen',
        'page_title' => 'Douwe Egberts Aroma Rood 1 kilo bonen',
        'page_pack_quantity' => '1000.000',
        'page_pack_unit' => 'g',
        'page_price' => '17.49',
        'page_currency' => 'EUR',
        'read_at' => $now,
        'second_chance' => 0.9,
        'status' => WebFindingStatus::Proposed,
        'fingerprint' => WebShopFinding::fingerprintFor($product),
    ]);
    WebDiscovery::mark($product, WebDiscoveryState::Done);
    echo 'Proposed.' . PHP_EOL;
    exit(0);
}

$user = $existing ?? new User(['email' => $email]);
$user->forceFill([
    'email' => $email,
    'name' => 'Eye Verify Web Suggestions',
    'password' => Hash::make($password),
    'email_verified_at' => $now,
    'is_admin' => false,
    'trial_ends_at' => $now->addDays(30),
    'shop_checks' => true,
])->save();
Product::query()->where('user_id', $user->id)->get()->each(fn (Product $product) => $product->delete());

$product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Douwe Egberts Aroma Rood koffiebonen 1 kg', 'currency' => 'EUR']);
Shop::factory()->for($product)->create(['url' => 'https://www.eye-verify-shop.test/p/1', 'host' => 'eye-verify-shop.test', 'current_price' => '18.99', 'currency' => 'EUR', 'pack_quantity' => '1000.00', 'pack_unit' => 'g']);
$product->refresh()->recomputeCheapestShop();
WebDiscovery::markQueued($product);

file_put_contents($fixturePath, json_encode(['email' => $email, 'password' => $password, 'productId' => $product->id]));
echo json_encode(['email' => $email, 'product' => $product->id, 'fixture' => $fixturePath, 'pro' => $user->refresh()->wantsShopChecks()]) . PHP_EOL;
