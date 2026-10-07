<?php declare(strict_types=1);

// Seeds a throwaway account for the "What's new" media, one per scenario:
//
//   php tools/changelog-media/seed.php screenshots   capture.mjs: a Pro account with the AI shop
//                                                    check, grocery products the daily dataset has
//                                                    rows for, and one web finding
//   php tools/changelog-media/seed.php video         capture-screens.mjs: products at two or three
//                                                    shops each, most of them on the shopping list;
//                                                    add `--dashboard` for the dashboard-* flows
//
// Add `--teardown` to delete that account and everything under it. Every name
// is fictional, because the media are published. It sets a known password,
// so it refuses anything but a local environment.

use App\Enums\AiPromptPlace;
use App\Enums\WebFindingStatus;
use App\Models\LargeDropCheck;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use App\Models\WebShopFinding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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
    // The AI offers (the Pro dialog, each place's prompt) are not what these shots are about.
    'ai_offer_shown_at' => $now,
    'ai_prompt_dismissals' => array_fill_keys(array_column(AiPromptPlace::cases(), 'value'), $now->toIso8601String()),
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

    // Changes of the lowest price over two shops, each with what DipCatch
    // did: a wrong price caught, a large drop that held and alerted, and the
    // alert price reached.
    $tablets = $track('Dishwasher tablets 60 pcs', ['ah.nl' => '14.99', 'jumbo.com' => '11.99']);
    $tablets->forceFill(['target_price' => '12.49'])->save();
    $shopAt = $tablets->shops->keyBy('host');
    $tablets->cheapestHistory()->delete();
    $changes = [
        // [days ago, host, price]
        [62, 'jumbo.com', '13.49'],
        [44, 'jumbo.com', '4.99'],
        [43.9, 'jumbo.com', '13.49'],
        [27, 'ah.nl', '10.99'],
        [19, 'ah.nl', '14.99'],
        [5, 'jumbo.com', '11.99'],
    ];
    $checkIds = [];

    foreach ($changes as $index => [$daysAgo, $host, $price]) {
        $startedAt = $now->subMinutes((int) ($daysAgo * 1440));
        $check = PriceCheck::factory()->for($shopAt[$host])->create(['price' => $price, 'checked_at' => $startedAt]);
        $checkIds[] = $check->id;
        $next = $changes[$index + 1] ?? null;
        ProductCheapestHistory::query()->create([
            'product_id' => $tablets->id,
            'cheapest_shop_id' => $shopAt[$host]->id,
            'cheapest_price' => $price,
            'started_at' => $startedAt,
            'ended_at' => $next === null ? null : $now->subMinutes((int) ($next[0] * 1440)),
            'triggering_price_check_id' => $check->id,
        ]);
    }

    $alert = static fn (int $checkId, string $reference, string $price, CarbonImmutable $firedAt): PriceDropEvent => PriceDropEvent::factory()->create([
        'product_id' => $tablets->id,
        'user_id' => $user->id,
        'price_check_id' => $checkId,
        'currency' => 'EUR',
        'reference_price' => $reference,
        'new_price' => $price,
        'drop_pct' => round(((float) $reference - (float) $price) / (float) $reference * 100, 4),
        'drop_abs' => round((float) $reference - (float) $price, 2),
        'fired_at' => $firedAt,
    ]);
    LargeDropCheck::factory()->rejected()->create([
        'shop_id' => $shopAt['jumbo.com']->id,
        'product_id' => $tablets->id,
        'price_check_id' => $checkIds[1],
        'price' => '4.99',
        'asked_at' => $now->subDays(44),
    ]);
    $answer = PriceCheck::factory()->for($shopAt['ah.nl'])->create(['price' => '10.99', 'checked_at' => $now->subDays(27)->addHour()]);
    LargeDropCheck::factory()->confirmed()->create([
        'shop_id' => $shopAt['ah.nl']->id,
        'product_id' => $tablets->id,
        'price_check_id' => $checkIds[3],
        'price' => '10.99',
        'asked_at' => $now->subDays(27),
        'resolved_by_price_check_id' => $answer->id,
    ]);
    $alert($answer->id, '13.49', '10.99', $now->subDays(27)->addHour());
    TargetPriceEvent::factory()->create([
        'product_id' => $tablets->id,
        'user_id' => $user->id,
        'shop_id' => $shopAt['jumbo.com']->id,
        'currency' => 'EUR',
        'target' => '12.49',
        'price' => '11.99',
        'fired_at' => $now->subDays(5)->addHours(2),
    ]);
    $fixture['tabletsId'] = $tablets->id;
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

    // `--dashboard`: a few products in a drop, so each shop has an offer and
    // "Where to shop this week" fills, and two at their alert price. Behind a
    // flag, because it regroups the Products page the other flows frame.
    $drops = in_array('--dashboard', $argv, true) ? ['coffee' => '14.99', 'laundry' => '10.49', 'pizza' => '2.49', 'catfood' => '7.49'] : [];

    foreach ($drops as $art => $reference) {
        $product = Product::query()->with('cheapestShop')->findOrFail($fixture['products'][$art]);
        $price = (string) $product->cheapest_price;
        $firedAt = $now->subDays(2);
        // A pack size read from the title puts the drop on the unit price.
        $unit = $product->best_value_pack_unit;
        $perUnit = static fn (string $amount): ?float => $unit === null ? null
            : round((float) $amount / (float) $product->best_value_pack_quantity * ($unit === 'piece' ? 1 : 1000), 4);
        PriceDropEvent::factory()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'price_check_id' => PriceCheck::factory()->for($product->cheapestShop)->create(['price' => $price, 'checked_at' => $firedAt])->id,
            'triggered_by_shop_id' => $product->cheapest_shop_id,
            'currency' => 'EUR',
            'reference_price' => $reference,
            'new_price' => $price,
            'comparison_unit' => $unit,
            'reference_unit_price' => $perUnit($reference),
            'new_unit_price' => $perUnit($price),
            'drop_pct' => round(((float) $reference - (float) $price) / (float) $reference * 100, 4),
            'drop_abs' => round((float) $reference - (float) $price, 2),
            'fired_at' => $firedAt,
        ]);
        $product->forceFill(['last_notified_price' => $price, 'last_notified_at' => $firedAt])->save();
    }

    foreach ($drops === [] ? [] : ['toothpaste' => '2.99', 'litter' => '5.99'] as $art => $target) {
        Product::query()->whereKey($fixture['products'][$art])->update(['target_price' => $target]);
    }
}

file_put_contents($fixturePath, json_encode($fixture));
echo json_encode(['email' => $email, 'fixture' => $fixturePath]) . PHP_EOL;
