<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\Hosts\ZooplusAdapter;
use App\Support\UrlNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a sample tracked product (Feliway Classic 3-pack diffuser at
 * zooplus.nl). The shop is wired to {@see ZooplusAdapter}, which keys on
 * the `?activeVariant=` query string and reads the price out of the
 * active-variant cell — so re-checks on a URL change pick up the new
 * variant automatically. Initial price is a placeholder; the next
 * scheduled {@see CheckShopPrice} run overwrites it with the live value.
 */
final class ZooplusFeliwaySeeder extends Seeder
{
    private const string PRODUCT_URL = 'https://www.zooplus.nl/shop/katten/verzorging/huisapotheek/verdamper/169589?activeVariant=169589.10';

    private const string PLACEHOLDER_PRICE = '27.99';

    private const string CURRENCY = 'EUR';

    public function run(): void
    {
        $owner = User::query()->orderBy('created_at')->first();

        if (! $owner instanceof User) {
            $this->command->warn(
                'ZooplusFeliwaySeeder skipped: no User found. Run AdminUserSeeder first (set ADMIN_EMAIL + ADMIN_PASSWORD).',
            );

            return;
        }

        DB::transaction(function () use ($owner): void {
            $product = Product::query()->updateOrCreate(
                [
                    'user_id' => $owner->id,
                    'title' => 'Feliway Classic Verdamper 3-pack',
                ],
                [
                    'image_url' => 'https://media.zooplus.com/bilder/8/400/67609_mhi_ceva_feliway_classic_hs_07_8.jpg',
                    'currency' => self::CURRENCY,
                    'drop_threshold_pct' => 5.00,
                    'drop_threshold_abs' => 1.00,
                    'active' => true,
                ],
            );

            $normalizedUrl = UrlNormalizer::normalize(self::PRODUCT_URL);
            $urlHash = UrlNormalizer::hash($normalizedUrl);

            $shop = Shop::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'url_hash' => $urlHash,
                ],
                [
                    'url' => $normalizedUrl,
                    'adapter_key' => 'zooplus',
                    'price_selector' => null,
                    'title_selector' => null,
                    'image_selector' => null,
                    'variant_key' => null,
                    'currency' => self::CURRENCY,
                    'initial_price' => self::PLACEHOLDER_PRICE,
                    'initial_checked_at' => now(),
                    'current_price' => self::PLACEHOLDER_PRICE,
                    'current_in_stock' => true,
                    'last_checked_at' => now(),
                    'last_success_at' => now(),
                    'last_status' => ScrapeStatus::Ok->value,
                    'last_error' => null,
                    'consecutive_failures' => 0,
                    'consecutive_5xx_failures' => 0,
                    'health' => 'ok',
                    'active' => true,
                ],
            );

            $check = PriceCheck::query()->create([
                'shop_id' => $shop->id,
                'price' => self::PLACEHOLDER_PRICE,
                'currency' => self::CURRENCY,
                'in_stock' => true,
                'status' => ScrapeStatus::Ok->value,
                'checked_at' => now(),
            ]);

            $product->recomputeCheapestShop((int) $check->id);
        });

        $this->command->info('ZooplusFeliwaySeeder: Feliway Classic Verdamper 3-pack seeded with ZooplusAdapter shop.');
    }
}
