<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * One account at the edge of everything, for trying to break the UI by hand.
 *
 * Local only and never called from DatabaseSeeder: run it with
 * `php artisan db:seed --class=StressSeeder`. It gives stress@example.test
 * (password `password`) Pro's full 250 products, titles built to break a
 * layout or an escaper, up to twelve shops a product, extreme prices, and
 * alerts on many of them. Run again and it replaces its own account.
 */
final class StressSeeder extends Seeder
{
    private const string EMAIL = 'stress@example.test';

    /** @var list<string> */
    private const array TITLES = [
        '<img src=x onerror=alert(1)> Koffie',
        '"><script>alert(document.cookie)</script>',
        '| broken | markdown | table |',
        '[click me](javascript:alert(1)) **bold** _it_ `code`',
        '{{ 7*7 }} {!! "raw" !!} @php echo 1; @endphp',
        'حليب كامل الدسم ١ لتر', // right to left
        '👨‍👩‍👧‍👦🧑🏽‍🍳 Familiepak 🍕🍕🍕 extra kaas',
        'Z̵̢̛̖̗͎̼̣̹̪̓̽̅̎̓a̶̧̛̝̲͍̗͍̎̆͑l̴̰̫̼̔g̸̢̨̢̞͂̈́o̵̢̧̠͖͋ pasta',
        'Ω≈ç√∫˜µ≤≥÷ åß∂ƒ©˙∆˚¬ — “quotes” ‘single’ …',
        "Line one\nLine two\r\nLine three\tTabbed",
        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        'x',
        '   leading and trailing spaces   ',
        '%_\\ LIKE wildcards 100% _underscore_ back\\slash',
        "O'Reilly's \"Special\" Edition & Co. <b>bold</b>",
        '𝓕𝓪𝓷𝓬𝔂 𝕌𝕟𝕚𝕔𝕠𝕕𝕖 𝚖𝚊𝚝𝚑 𝔣𝔯𝔞𝔨𝔱𝔲𝔯',
        '日本語の商品名がとても長い場合の表示テスト用のタイトルです',
        '0',
        'null',
        '../../../../etc/passwd',
    ];

    /** @var list<string> */
    private const array HOSTS = [
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'bol.com', 'amazon.nl', 'zooplus.nl',
        'a-very-long-subdomain-name-for-a-shop.with.many.labels.example.com',
        'xn--bcher-kva.example', 'shop.example.com',
    ];

    public function run(): void
    {
        User::query()->where('email', self::EMAIL)->first()?->delete();

        $user = User::factory()->create([
            'name' => str_repeat('Stress 🧪 Tester ', 14),
            'email' => self::EMAIL,
            'comped_until' => now()->addYears(5),
        ]);

        $categories = ProductCategory::cases();

        foreach (range(1, 250) as $i) {
            $base = self::TITLES[$i % count(self::TITLES)];
            $title = Str::limit($base . ' #' . $i . ' ' . str_repeat('lang ', $i % 7 === 0 ? 45 : 0), 255, '');

            $product = Product::factory()->create([
                'user_id' => $user->id,
                'title' => $title,
                'category' => $i % 5 === 0 ? null : $categories[$i % count($categories)],
                'share_slug' => $i % 9 === 0 ? Str::random(32) : null,
                'image_url' => match ($i % 4) {
                    0 => null,
                    1 => 'https://placehold.co/2000x40/png?text=' . rawurlencode('wide ' . $i),
                    2 => 'https://example.invalid/missing-image-' . $i . '.jpg',
                    default => 'https://placehold.co/40x2000/png?text=tall',
                },
                'target_price' => $i % 6 === 0 ? 0.01 : null,
                'active' => $i % 17 !== 0,
            ]);

            $this->seedShops($product, $i);
            $product->recomputeCheapestShop();

            if ($i % 3 === 0) {
                PriceDropEvent::factory()->for($user)->for($product)->create([
                    'triggered_by_shop_id' => $product->shops()->value('id'),
                    'fired_at' => now()->subHours($i % 48),
                    'drop_pct' => $i % 2 === 0 ? 99.9999 : 0.0100,
                    'drop_abs' => $i % 2 === 0 ? 99999.99 : 0.01,
                    'new_price' => 0.01,
                    'reference_price' => 99999.99,
                ]);
            }

            if ($i % 6 === 0) {
                TargetPriceEvent::factory()->for($user)->for($product)->create([
                    'shop_id' => $product->shops()->value('id'),
                    'target' => 0.01,
                    'price' => 0.01,
                    'deal' => '<b>99 voor €0,01</b> & "gratis"',
                    'fired_at' => now()->subHours($i % 24),
                ]);
            }
        }
    }

    private function seedShops(Product $product, int $i): void
    {
        $count = 1 + ($i % 12);

        foreach (range(1, $count) as $n) {
            $host = self::HOSTS[($i + $n) % count(self::HOSTS)];
            $price = match (($i + $n) % 6) {
                0 => 0.01,
                1 => 99999999.99,
                2 => 1234.5,
                default => round(1 + (($i * 7 + $n * 13) % 5000) / 100, 2),
            };

            $shop = Shop::factory()->for($product)->create([
                'url' => 'https://' . $host . '/p/' . $i . '-' . $n . '?' . str_repeat('utm_source=x&', $n % 3 === 0 ? 60 : 1) . 'q=' . rawurlencode('"<>\''),
                'currency' => $n % 7 === 0 ? 'GBP' : 'EUR',
                'current_price' => $price,
                'initial_price' => $price,
                'pack_quantity' => match ($n % 5) {
                    0 => null,
                    1 => 0.01,
                    2 => 999999,
                    default => 250,
                },
                'pack_unit' => match ($n % 5) {
                    0 => null,
                    1, 3 => 'g',
                    2 => 'piece',
                    default => 'ml',
                },
                'current_in_stock' => $n % 4 !== 0,
                'last_status' => $n % 8 === 0 ? 'parse_error' : 'ok',
                'last_error' => $n % 8 === 0 ? str_repeat('<error> & "failure" ', 30) : null,
                'health' => $n % 8 === 0 ? 'failing' : 'ok',
                'bundle_quantity' => $n % 9 === 0 ? 99 : null,
                'bundle_total_price' => $n % 9 === 0 ? 0.99 : null,
                'single_item_price' => $n % 9 === 0 ? $price : null,
                'promotion_label' => $n % 10 === 0 ? str_repeat('Mega actie! ', 20) : null,
                'promotion_ends_at' => $n % 10 === 0 ? now()->addDays(2) : null,
            ]);

            PriceCheck::factory()->count(3)->create(['shop_id' => $shop->id]);
        }
    }
}
