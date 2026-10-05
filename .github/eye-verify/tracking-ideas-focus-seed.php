<?php declare(strict_types=1);

// Seeds the throwaway account tracking-ideas-focus.mjs drives: one product,
// so the dashboard shows the "what else do you buy" strip. `--one-left` marks
// every idea but one as done. `--teardown` deletes the account. It creates an
// account with a known password, so it refuses anything but a local environment.

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\TrackingIdeaMarkState;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\TrackingIdeaChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

if (! $app->environment('local')) {
    fwrite(STDERR, 'Refusing to seed: APP_ENV is ' . $app->environment() . ', not local.' . PHP_EOL);
    exit(1);
}

$email = 'eye-verify-tracking-ideas@dipcatch.test';
$password = getenv('FIXTURE_PASSWORD') ?: 'eye-verify-local-only';
$fixturePath = getenv('FIXTURE_PATH') ?: '/tmp/tracking-ideas-focus-eye-verify.json';
$now = CarbonImmutable::now();
$existing = User::query()->where('email', $email)->first();

if (in_array('--teardown', $argv, true)) {
    if ($existing !== null) {
        Product::query()->where('user_id', $existing->id)->get()->each(fn (Product $product) => $product->delete());
        $existing->trackingIdeaMarks()->delete();
        $existing->delete();
    }

    @unlink($fixturePath);
    echo 'Torn down.' . PHP_EOL;
    exit(0);
}

$user = $existing ?? new User(['email' => $email]);
$user->forceFill([
    'email' => $email,
    'name' => 'Eye Verify Tracking Ideas',
    // Kept when it still matches: a new hash signs out the browser the script drives.
    'password' => $existing !== null && Hash::check($password, (string) $existing->password) ? $existing->password : Hash::make($password),
    'email_verified_at' => $now,
    'is_admin' => false,
    'tracking_ideas_hidden_at' => null,
])->save();

Product::query()->where('user_id', $user->id)->get()->each(fn (Product $product) => $product->delete());
$user->trackingIdeaMarks()->delete();

$product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Eye verify test product', 'currency' => 'EUR']);
Shop::factory()->for($product)->create(['url' => 'https://www.eye-verify-shop.test/p/ideas', 'host' => 'eye-verify-shop.test', 'current_price' => '1.00', 'currency' => 'EUR']);

$open = TrackingIdeaChecklist::for($user)->open;
$last = $open[array_key_last($open)];

if (in_array('--one-left', $argv, true)) {
    foreach ($open as $idea) {
        if ($idea !== $last) {
            $user->trackingIdeaMarks()->create(['idea' => $idea->value, 'state' => TrackingIdeaMarkState::Done, 'marked_at' => $now]);
        }
    }
}

file_put_contents($fixturePath, json_encode(['email' => $email, 'password' => $password, 'lastIdea' => $last->value]));
echo 'Seeded ' . $email . PHP_EOL;
