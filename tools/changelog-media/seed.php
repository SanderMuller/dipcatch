<?php declare(strict_types=1);

// Seeds a throwaway account for the "What's new" media, one per scenario:
//
//   php tools/changelog-media/seed.php screenshots   capture.mjs: a Pro account with the AI shop
//                                                    check, grocery products the daily dataset has
//                                                    rows for, and one web finding
//   php tools/changelog-media/seed.php video         capture-screens.mjs: products at two or three
//                                                    shops each, most of them on the shopping list
//
// Add `--teardown` to delete that account and everything under it. Every name
// is fictional, because the media are published. It sets a known password,
// so it refuses anything but a local environment.

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

$scenario = array_values(array_filter(array_slice($argv, 1), static fn (string $arg): bool => ! str_starts_with($arg, '--')))[0] ?? '';

if (! in_array($scenario, ['screenshots', 'video'], true)) {
    fwrite(STDERR, 'Usage: php tools/changelog-media/seed.php screenshots|video [--teardown]' . PHP_EOL);
    exit(1);
}

$email = "changelog-media-{$scenario}@dipcatch.test";
$password = getenv('FIXTURE_PASSWORD') ?: 'changelog-media-local-only';
$fixturePath = "/tmp/changelog-media-{$scenario}.json";
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
    'name' => 'Sam Example',
    'password' => Hash::make($password),
    'email_verified_at' => $now,
    'is_admin' => false,
    'trial_ends_at' => $now->addDays(30),
    'shop_checks' => $scenario === 'screenshots',
    // The "switch on AI" prompt is not what any of these shots are about.
    'ai_prompts_dismissed_at' => $scenario === 'video' ? $now : null,
])->save();
Product::query()->where('user_id', $user->id)->get()->each(fn (Product $product) => $product->delete());

/**
 * A real shop name reads right in the media. The factory marks each shop
 * checked just now, so no scheduled check picks it up before the teardown.
 *
 * @param  array<string, string>  $prices  host => price
 */
$track = static function (string $title, array $prices, ?string $image = null) use ($user): Product {
    // No factory photo: its placeholder prints a random word on the tile.
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => $title, 'currency' => 'EUR', 'image_url' => $image]);

    foreach ($prices as $host => $price) {
        Shop::factory()->for($product)->create(['url' => "https://www.{$host}/changelog-media/" . md5($title), 'current_price' => $price, 'currency' => 'EUR']);
    }

    $product->refresh()->recomputeCheapestShop();
    // No paid AI checks from a capture run.
    Cache::put("shop-suggestions:verify:{$product->id}", true, now()->addHour());

    return $product;
};

$fixture = ['email' => $email, 'password' => $password];

if ($scenario === 'screenshots') {
    foreach (['Maggi Jus pikant' => '2.19', 'Knorr Jus' => '1.99', 'Bonduelle Broccoliroosjes' => '1.49', 'Le Rustique Camembert' => '3.29'] as $title => $price) {
        $track($title, ['jumbo.com' => $price]);
    }

    $coffee = $track('Douwe Egberts Aroma Rood koffiebonen 1 kg', ['jumbo.com' => '18.99']);
    WebShopFinding::query()->create([
        'product_id' => $coffee->id,
        'url' => 'https://koffiehenk.nl/douwe-egberts-aroma-rood-bonen',
        'url_hash' => hash('sha256', 'changelog-media-koffiehenk'),
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
    $fixture['coffeeId'] = $coffee->id;
}

if ($scenario === 'video') {
    // Each product at two or three shops, so the list groups them by best buy
    // and skipping a shop moves them. The photos are the video template's own
    // drawn packs; capture-screens.mjs serves them for this made-up host.
    $products = [
        ['Coffee beans 1 kg', 'coffee', ['jumbo.com' => '11.99', 'ah.nl' => '13.49'], true],
        ['Laundry gel 2 L', 'laundry', ['ah.nl' => '8.29', 'jumbo.com' => '8.99', 'dirk.nl' => '9.19'], true],
        ['Pepperoni pizza', 'pizza', ['dirk.nl' => '1.89', 'ah.nl' => '2.49'], true],
        ['Whitening toothpaste 75 ml', 'toothpaste', ['ah.nl' => '2.79', 'jumbo.com' => '2.99'], true],
        ['Protein bars 12 × 45 g', 'bars', ['jumbo.com' => '14.99', 'dirk.nl' => '15.49'], true],
        ['Cat food 12 × 85 g', 'catfood', ['ah.nl' => '6.25', 'dirk.nl' => '6.49'], true],
        ['Cat litter 10 L', 'litter', ['dirk.nl' => '5.99', 'ah.nl' => '6.79'], false],
        ['Vitamin D 90 tablets', 'vitamins', ['ah.nl' => '4.49', 'jumbo.com' => '4.99'], false],
    ];

    foreach ($products as [$title, $art, $prices, $listed]) {
        $product = $track($title, $prices, "https://product-photos.changelog-media.test/{$art}.png");

        if ($listed) {
            $product->addToShoppingList();
        }

        $fixture['products'][$art] = $product->id;
    }
}

file_put_contents($fixturePath, json_encode($fixture));
echo json_encode(['email' => $email, 'fixture' => $fixturePath]) . PHP_EOL;
