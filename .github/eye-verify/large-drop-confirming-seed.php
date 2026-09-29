<?php declare(strict_types=1);

// Seeds a throwaway account for large-drop-confirming.mjs: one product whose
// latest reading is a large drop still waiting for a second reading, and one
// whose drop a second reading already confirmed. The queue and notifications
// are faked, so no confirmation job runs and nothing is sent. `--teardown`
// deletes the account and everything under it. Local only.

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

if (! $app->environment('local')) {
    fwrite(STDERR, 'Refusing to seed: APP_ENV is ' . $app->environment() . ', not local.' . PHP_EOL);
    exit(1);
}

$email = 'eye-verify-drops@dipcatch.test';
$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/large-drop-eye-verify.json';
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

Queue::fake();
Notification::fake();

$user = $existing ?? new User(['email' => $email]);
$user->forceFill([
    'email' => $email,
    'name' => 'Eye Verify Drops',
    'password' => Hash::make($password),
    'email_verified_at' => $now,
    'is_admin' => false,
    'trial_ends_at' => $now->addDays(30),
])->save();
$purge($user);

/** A product at 100.00 for ten days on one scraped shop, then the given readings. */
$make = function (string $title, array $prices) use ($user, $now): Product {
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'title' => $title,
        'currency' => 'EUR',
        'drop_threshold_pct' => '5.00',
        'drop_threshold_abs' => '1.00',
        'image_url' => 'https://picsum.photos/seed/' . md5($title) . '/600/450',
    ]);

    $shop = Shop::factory()->create([
        'product_id' => $product->id,
        'url' => 'https://www.koffievoordeel.nl/p/ev-' . $product->id,
        'host' => 'koffievoordeel.nl',
        'currency' => 'EUR',
        'current_price' => '100.00',
    ]);

    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '100.00'])->save();
    ProductCheapestHistory::factory()->create([
        'product_id' => $product->id,
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '100.00',
        'started_at' => $now->subDays(10),
        'ended_at' => null,
    ]);
    PriceCheck::factory()->create(['shop_id' => $shop->id, 'price' => '100.00', 'checked_at' => $now->subDays(10)]);

    foreach ($prices as $price) {
        $check = PriceCheck::factory()->create(['shop_id' => $shop->id, 'price' => $price, 'checked_at' => now()]);
        $shop->update(['current_price' => $price]);
        $product->refresh()->recomputeCheapestShop((int) $check->id);
    }

    return $product->refresh();
};

$waiting = $make('EV Espresso Beans 1 kg', ['40.00']);
$confirmed = $make('EV Filter Coffee 1 kg', ['40.00', '40.00']);

file_put_contents($fixturePath, json_encode([
    'email' => $email,
    'password' => $password,
    'waitingId' => $waiting->id,
    'confirmedId' => $confirmed->id,
    'confirmedEvents' => PriceDropEvent::query()->where('product_id', $confirmed->id)->count(),
]));
echo 'Seeded ' . $fixturePath . PHP_EOL;
