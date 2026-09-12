<?php declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

// This script creates accounts with a known password and deletes the fixture
// product's shops and history. It must never touch a real environment.
if (! $app->environment('local')) {
    fwrite(STDERR, 'Refusing to seed: APP_ENV is ' . $app->environment() . ', not local.' . PHP_EOL);
    exit(1);
}

$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$now = CarbonImmutable::now();

function makeAccount(string $email, string $name, ?CarbonImmutable $trialEndsAt, string $password, CarbonImmutable $now): array
{
    $user = User::query()->firstOrNew(['email' => $email]);
    // forceFill: the model guards email_verified_at and trial_ends_at, and a
    // fixture account has to be both verified and (for the Pro case) on a
    // hand-granted trial.
    $user->forceFill([
        'name' => $name,
        'password' => Hash::make($password),
        'email_verified_at' => $now,
        'is_admin' => false,
        'trial_ends_at' => $trialEndsAt,
    ])->save();

    $product = Product::query()->updateOrCreate(
        ['user_id' => $user->id, 'title' => 'Eye-verify history product'],
        [
            'currency' => 'EUR',
            'active' => true,
            'created_at' => $now->subDays(420),
        ],
    );

    Shop::query()->where('product_id', $product->id)->delete();
    ProductCheapestHistory::query()->where('product_id', $product->id)->delete();

    $shops = [];
    foreach (['ah.nl', 'jumbo.com'] as $i => $host) {
        $shops[] = Shop::factory()->inactive()->create([
            'product_id' => $product->id,
            'url' => "https://{$host}/eye-verify/{$product->id}/{$i}",
            'currency' => 'EUR',
            // Only the Pro fixture states a pack size, so one run covers the
            // per-unit axis both present and absent.
            // Only the Pro fixture states a pack size, and only on the shop
            // that falls outside the default range.
            'pack_quantity' => $trialEndsAt !== null && $i === 1 ? 1500 : null,
            'pack_unit' => $trialEndsAt !== null && $i === 1 ? 'g' : null,
        ]);
    }

    // Segments spread across 420 days so the 90-day clamp visibly hides most
    // of the line for a free account.
    $prices = [24.95, 22.50, 26.00, 19.99, 23.40, 21.10, 18.75, 20.40, 17.99];
    $offsets = [420, 360, 300, 240, 180, 120, 75, 40, 10];
    $previous = null;
    foreach ($offsets as $k => $days) {
        $started = $now->subDays($days);
        $segment = ProductCheapestHistory::query()->create([
            'product_id' => $product->id,
            // The pack-size shop is only ever cheapest outside the default
            // 90-day window, so the per-unit axis has to be decided for the
            // product rather than for the range in view.
            'cheapest_shop_id' => ($days >= 200 ? $shops[1] : $shops[0])->id,
            'cheapest_price' => $prices[$k],
            'started_at' => $started,
            'ended_at' => null,
        ]);
        if ($previous !== null) {
            $previous->update(['ended_at' => $started]);
        }
        $previous = $segment;
    }

    $product->update([
        'cheapest_shop_id' => $shops[0]->id,
        'cheapest_price' => end($prices),
    ]);

    return [$user, $product];
}

[$free, $freeProduct] = makeAccount('eye-verify-free@dipcatch.test', 'Eye Verify Free', null, $password, $now);
[$pro, $proProduct] = makeAccount('eye-verify-pro@dipcatch.test', 'Eye Verify Pro', $now->addDays(30), $password, $now);

echo json_encode([
    'free' => ['email' => $free->email, 'plan' => $free->plan()->value, 'product' => $freeProduct->id, 'segments' => $freeProduct->cheapestHistory()->count()],
    'pro' => ['email' => $pro->email, 'plan' => $pro->plan()->value, 'product' => $proProduct->id],
], JSON_PRETTY_PRINT), PHP_EOL;
