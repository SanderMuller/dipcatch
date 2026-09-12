<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Plan;
use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Models\Invitation;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\StripeDispute;
use App\Models\StripePayment;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\ReferenceValue;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoOffer;
use Database\Seeders\Demo\DemoProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;

/**
 * Local demo data: a populated app for both panels after
 * `php artisan migrate:fresh --seed`.
 *
 * The rows are written directly rather than through the domain engine.
 * `Product::recomputeCheapestShop()` runs `DetectDrop`, which queues a
 * `PriceDropNotification` — a seeder must not depend on a worker or send
 * web push, so the cheapest pointer, the history segments, the drop events
 * and the database notifications are all written here.
 *
 * Never runs in production, and skips itself when its demo owner already
 * exists: factory rows use `fake()->unique()`, so the seeder is gated as a
 * whole instead of pretending each row is idempotent.
 */
final class DemoSeeder extends Seeder
{
    private const string PASSWORD = 'password';

    private const string DEMO_EMAIL = 'demo@dipcatch.test';

    private const string PRO_EMAIL = 'pro@dipcatch.test';

    private const string FREE_EMAIL = 'free@dipcatch.test';

    private const string EMPTY_EMAIL = 'newbie@dipcatch.test';

    private const string FALLBACK_ADMIN_EMAIL = 'admin@dipcatch.test';

    /** Days of price history written per offer. */
    private const int HISTORY_DAYS = 75;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('DemoSeeder skipped: demo data is never seeded in production.');

            return;
        }

        if (User::query()->where('email', self::DEMO_EMAIL)->exists()) {
            $this->command->info('DemoSeeder skipped: ' . self::DEMO_EMAIL . ' already exists. Run migrate:fresh --seed for a clean set.');

            return;
        }

        // Deterministic demo data: the same run twice gives the same prices.
        fake()->seed(20260909);

        $admin = $this->ensureAdmin();

        $demo = $this->createUser(self::DEMO_EMAIL, 'Demo Shopper', [
            'comped_until' => now()->addYear(),
            'comped_reason' => 'Demo account',
            'notify_via_push' => true,
        ]);

        $pro = $this->createUser(self::PRO_EMAIL, 'Petra Pro');
        $free = $this->createUser(self::FREE_EMAIL, 'Frank Free');
        $this->createUser(self::EMPTY_EMAIL, 'Nina Newbie');

        foreach ($this->demoCatalog() as $spec) {
            $this->seedProduct($demo, $spec);
        }

        foreach ($this->proCatalog() as $spec) {
            $this->seedProduct($pro, $spec);
        }

        foreach ($this->freeCatalog() as $spec) {
            $this->seedProduct($free, $spec);
        }

        $this->seedBilling($pro);
        $this->seedCrowd($admin);
        $this->seedInvitations($admin);

        $this->command->info('DemoSeeder: demo data seeded.');
        $this->command->info('  App    https://dipcatch.test  — ' . self::DEMO_EMAIL . ' / ' . self::PASSWORD);
        $this->command->info('  Admin  https://dipcatch.test/admin — ' . $admin->email . ' / ' . self::PASSWORD . ' (unless ADMIN_PASSWORD is set)');
    }

    /**
     * The admin panel needs an account that can reach it. `AdminUserSeeder`
     * makes one only when ADMIN_EMAIL and ADMIN_PASSWORD are set, so a local
     * checkout with an empty `.env` gets a fallback here — that seeder itself
     * runs in production and stays untouched.
     */
    private function ensureAdmin(): User
    {
        $configured = config('dipcatch.admin.email');

        if (is_string($configured) && $configured !== '') {
            $admin = User::query()->where('email', $configured)->first();

            if ($admin instanceof User) {
                return $admin;
            }
        }

        return $this->createUser(self::FALLBACK_ADMIN_EMAIL, 'Demo Admin', ['is_admin' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(string $email, string $name, array $attributes = []): User
    {
        return User::factory()->state($attributes)->create([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
        ]);
    }

    // ---------------------------------------------------------------------
    // Catalogs
    // ---------------------------------------------------------------------

    /**
     * @return list<DemoProduct>
     */
    private function demoCatalog(): array
    {
        return [
            new DemoProduct(
                title: 'Douwe Egberts Aroma Rood koffiebonen 1 kg',
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi123456/douwe-egberts-aroma-rood-bonen', 13.99, 1000, 'g'),
                    new DemoOffer('jumbo.com', 'producten/douwe-egberts-aroma-rood-koffiebonen-1kg', 12.49, 1000, 'g'),
                    new DemoOffer('bol.com', 'nl/nl/p/douwe-egberts-aroma-rood-bonen-2x1kg/9200000012345678', 27.95, 2000, 'g'),
                ],
                unitPriceTarget: 12.50,
                shareSlug: 'aroma-rood-1kg',
                drop: 'recent',
            ),
            new DemoProduct(
                title: 'Zeeuws Meisje Roomboter 250 g',
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi222333/zeeuws-meisje-roomboter', 3.29, 250, 'g'),
                    new DemoOffer('jumbo.com', 'producten/zeeuws-meisje-roomboter-250g', 3.09, 250, 'g'),
                    new DemoOffer('dirk.nl', 'boodschappen/zuivel/boter/zeeuws-meisje-roomboter/1234', 2.89, 250, 'g'),
                ],
                drop: 'today',
            ),
            new DemoProduct(
                title: 'Coca-Cola Zero Sugar 6 x 1,5 L',
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi334455/coca-cola-zero-6-pack', 11.94, 9000, 'ml'),
                    new DemoOffer('jumbo.com', 'producten/coca-cola-zero-sugar-6x1-5l', 10.99, 9000, 'ml', promotion: 'live'),
                ],
            ),
            new DemoProduct(
                title: 'Pampers Baby-Dry maat 4 (174 stuks)',
                offers: [
                    new DemoOffer('bol.com', 'nl/nl/p/pampers-baby-dry-maat-4-174-luiers/9200000098765432', 44.99, 174, 'piece'),
                    new DemoOffer('amazon.nl', 'dp/B08PAMPERS4', 41.95, 174, 'piece'),
                ],
                drop: 'week',
            ),
            new DemoProduct(
                title: 'Whiskas Adult kattenvoer 100 zakjes',
                offers: [
                    new DemoOffer('zooplus.nl', 'shop/katten/kattenvoer_nat/whiskas/100-zakjes/123456', 39.99, 100, 'piece'),
                    new DemoOffer('bol.com', 'nl/nl/p/whiskas-adult-100-zakjes/9200000011112222', 43.50, 100, 'piece', state: 'out_of_stock'),
                ],
            ),
            new DemoProduct(
                title: 'Philips Hue White and Color Ambiance E27 (2-pack)',
                offers: [
                    new DemoOffer('coolblue.nl', 'product/912345/philips-hue-white-and-color-e27-duopack.html', 89.00, 2, 'piece'),
                    new DemoOffer('bol.com', 'nl/nl/p/philips-hue-white-and-color-e27-2-pack/9200000033334444', 94.99, 2, 'piece', state: 'unknown_stock'),
                ],
                drop: 'week',
            ),
            new DemoProduct(
                title: 'Nespresso Vertuo Barista Creations (30 capsules)',
                offers: [
                    new DemoOffer('bol.com', 'nl/nl/p/nespresso-vertuo-barista-30-capsules/9200000055556666', 21.45, 30, 'piece', state: 'failing'),
                    new DemoOffer('amazon.nl', 'dp/B08VERTUO30', 19.99, 30, 'piece'),
                ],
            ),
            new DemoProduct(
                title: 'Grolsch Premium Pilsner 24 x 30 cl',
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi556677/grolsch-premium-pilsner-24-pack', 18.99, 7200, 'ml', conditional: true),
                    new DemoOffer('jumbo.com', 'producten/grolsch-premium-pilsner-24x30cl', 17.49, 7200, 'ml', promotion: 'expired'),
                ],
            ),
            new DemoProduct(
                title: 'Garmin Forerunner 265',
                offers: [
                    new DemoOffer('coolblue.nl', 'product/934567/garmin-forerunner-265-zwart.html', 399.00, 1, 'piece'),
                    new DemoOffer('bol.com', 'nl/nl/p/garmin-forerunner-265/9200000077778888', 429.00, 1, 'piece', state: 'dead'),
                ],
            ),
            new DemoProduct(
                title: 'LEGO Icons Orchidee 10311',
                offers: [
                    new DemoOffer('bol.com', 'nl/nl/p/lego-icons-orchidee-10311/9200000099990000', 44.99, 1, 'piece', state: 'dead'),
                ],
                active: false,
            ),
        ];
    }

    /**
     * @return list<DemoProduct>
     */
    private function proCatalog(): array
    {
        return [
            new DemoProduct(
                title: 'Sony WH-1000XM5 koptelefoon',
                offers: [
                    new DemoOffer('coolblue.nl', 'product/901234/sony-wh-1000xm5-zwart.html', 299.00, 1, 'piece'),
                    new DemoOffer('bol.com', 'nl/nl/p/sony-wh-1000xm5/9200000044445555', 319.00, 1, 'piece'),
                    new DemoOffer('amazon.nl', 'dp/B09XM5SONY', 289.99, 1, 'piece'),
                ],
                drop: 'recent',
            ),
            new DemoProduct(
                title: 'Lay\'s Oven Baked Paprika 150 g',
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi667788/lays-oven-baked-paprika', 2.19, 150, 'g'),
                    new DemoOffer('dirk.nl', 'boodschappen/snacks/chips/lays-oven-baked/5678', 1.99, 150, 'g'),
                ],
            ),
            new DemoProduct(
                title: 'Royal Canin Medium Adult 15 kg',
                offers: [
                    new DemoOffer('zooplus.nl', 'shop/honden/droogvoer/royal_canin/medium/223344', 74.99, 15000, 'g'),
                    new DemoOffer('bol.com', 'nl/nl/p/royal-canin-medium-adult-15kg/9200000066667777', 79.95, 15000, 'g', state: 'failing'),
                ],
                drop: 'week',
            ),
        ];
    }

    /**
     * @return list<DemoProduct>
     */
    private function freeCatalog(): array
    {
        return [
            new DemoProduct(
                // One offer only, so the "add a second shop" NextStepsWidget
                // has something to point at.
                title: 'Robijn Wasmiddel Color 40 wasbeurten',
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi889900/robijn-color-wasmiddel', 9.99, 40, 'piece'),
                ],
            ),
            new DemoProduct(
                title: 'Tony\'s Chocolonely Melk 180 g',
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi990011/tonys-chocolonely-melk', 3.49, 180, 'g'),
                    new DemoOffer('jumbo.com', 'producten/tonys-chocolonely-melk-180g', 3.19, 180, 'g'),
                ],
            ),
        ];
    }

    // ---------------------------------------------------------------------
    // Product construction
    // ---------------------------------------------------------------------

    private function seedProduct(User $user, DemoProduct $spec): void
    {
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'title' => $spec->title,
            'image_url' => null,
            'currency' => 'EUR',
            'drop_threshold_pct' => 5.00,
            'drop_threshold_abs' => 0.50,
            'unit_price_target' => $spec->unitPriceTarget,
            'share_slug' => $spec->shareSlug,
            'active' => $spec->active,
            'created_at' => now()->subDays(self::HISTORY_DAYS + 5),
        ]);

        /** @var list<array{shop: Shop, prices: array<int, float>}> $offers */
        $offers = [];

        foreach ($spec->offers as $offer) {
            $offers[] = $this->seedShop($product, $offer);
        }

        $this->seedCheapestHistory($product, $offers);

        if ($spec->drop !== null) {
            $this->seedDrop($user, $product, $spec->drop);
        }
    }

    /**
     * @return array{shop: Shop, prices: array<int, float>}
     */
    private function seedShop(Product $product, DemoOffer $offer): array
    {
        $prices = $this->priceWalk($offer->price);

        $createdAt = now()->subDays(self::HISTORY_DAYS + 4);

        $shop = Shop::factory()->create([
            'product_id' => $product->id,
            'url' => $offer->url(),
            'adapter_key' => $this->adapterKey($offer->host),
            'currency' => 'EUR',
            'initial_price' => $prices[0],
            'initial_checked_at' => $createdAt,
            'current_price' => $offer->price,
            'current_in_stock' => match ($offer->state) {
                'out_of_stock' => false,
                'unknown_stock' => null,
                default => true,
            },
            'pack_quantity' => $offer->packQuantity,
            'pack_unit' => $offer->packUnit,
            'last_checked_at' => now()->subMinutes(fake()->numberBetween(5, 240)),
            'last_success_at' => match ($offer->state) {
                'failing' => now()->subDays(3),
                'dead' => now()->subDays(21),
                default => now()->subMinutes(fake()->numberBetween(5, 240)),
            },
            'last_status' => match ($offer->state) {
                'failing' => ScrapeStatus::EmptyMatch,
                'dead' => ScrapeStatus::HttpError,
                default => ScrapeStatus::Ok,
            },
            'last_error' => match ($offer->state) {
                'failing' => 'Price selector matched no element',
                'dead' => 'HTTP 404 Not Found',
                default => null,
            },
            'consecutive_failures' => match ($offer->state) {
                'failing' => 4,
                'dead' => 14,
                default => 0,
            },
            'health' => match ($offer->state) {
                'failing' => ShopHealth::Failing,
                'dead' => ShopHealth::Dead,
                default => ShopHealth::Ok,
            },
            // A dead offer stays active on purpose: that is what puts it in
            // the admin "needing attention" list.
            'active' => true,
            'conditional_price' => $offer->conditional ? round($offer->price * 0.85, 2) : null,
            'conditional_label' => $offer->conditional ? 'Met Bonuskaart' : null,
            'conditional_starts_at' => $offer->conditional ? now()->subDays(2) : null,
            'conditional_ends_at' => $offer->conditional ? now()->addDays(5) : null,
            'promotion_starts_at' => match ($offer->promotion) {
                'live' => now()->subDays(2),
                'expired' => now()->subDays(14),
                default => null,
            },
            'promotion_ends_at' => match ($offer->promotion) {
                'live' => now()->addDays(4),
                'expired' => now()->subDays(3),
                default => null,
            },
            'promotion_label' => match ($offer->promotion) {
                'live', 'expired' => '2 halen 1 betalen',
                default => null,
            },
            'created_at' => $createdAt,
        ]);

        $this->seedPriceChecks($shop, $prices, $offer->state, $shop->current_in_stock);

        return ['shop' => $shop, 'prices' => $prices];
    }

    /**
     * A price path ending on the offer's current price: it starts higher, so
     * the chart has a shape and the 30-day median sits above today.
     *
     * @return array<int, float> index 0 = oldest day, last = today
     */
    private function priceWalk(float $current): array
    {
        $prices = [];

        for ($day = 0; $day < self::HISTORY_DAYS; $day++) {
            $progress = $day / (self::HISTORY_DAYS - 1);
            $drift = 1.0 + 0.14 * (1 - $progress);
            $noise = 1.0 + fake()->randomFloat(4, -0.02, 0.02);
            $prices[] = round($current * $drift * $noise, 2);
        }

        $prices[self::HISTORY_DAYS - 1] = $current;

        return $prices;
    }

    /**
     * @param  array<int, float>  $prices
     * @param  bool|null  $inStock  What the offer records today: the checks
     *                              must agree with it, or the offer reads as
     *                              sold out while its last check says stocked.
     */
    private function seedPriceChecks(Shop $shop, array $prices, string $state, ?bool $inStock): void
    {
        $rows = [];

        foreach ($prices as $day => $price) {
            $checkedAt = now()->subDays(self::HISTORY_DAYS - 1 - $day)->setTime(6, 0);

            $rows[] = [
                'shop_id' => $shop->id,
                'price' => $price,
                'currency' => 'EUR',
                'in_stock' => $inStock,
                'raw' => '€ ' . number_format($price, 2, ',', '.'),
                'status' => ScrapeStatus::Ok->value,
                'error' => null,
                'checked_at' => $checkedAt,
            ];
        }

        // A failing or dead offer ends on failures — a health badge with a
        // clean check history reads as a contradiction.
        if ($state === 'failing' || $state === 'dead') {
            $failures = $state === 'dead' ? 14 : 4;

            for ($i = $failures; $i >= 1; $i--) {
                $rows[] = [
                    'shop_id' => $shop->id,
                    'price' => null,
                    'currency' => null,
                    'in_stock' => null,
                    'raw' => null,
                    'status' => ($state === 'dead' ? ScrapeStatus::HttpError : ScrapeStatus::EmptyMatch)->value,
                    'error' => $state === 'dead' ? 'HTTP 404 Not Found' : 'Price selector matched no element',
                    'checked_at' => now()->subHours($i * 6),
                ];
            }
        }

        PriceCheck::query()->insert($rows);
    }

    /**
     * Cheapest pointer plus the `product_cheapest_history` segments the price
     * chart and the drop reference both read. Written here rather than via
     * `recomputeCheapestShop()`, which would fire queued notifications.
     *
     * @param  list<array{shop: Shop, prices: array<int, float>}>  $offers
     */
    private function seedCheapestHistory(Product $product, array $offers): void
    {
        $eligible = array_values(array_filter(
            $offers,
            static fn (array $offer): bool => $offer['shop']->health !== ShopHealth::Dead
                && $offer['shop']->current_in_stock !== false,
        ));

        if ($eligible === []) {
            return;
        }

        $segments = [];
        $openPrice = null;
        $openShopId = null;
        $openStart = null;

        for ($day = 0; $day < self::HISTORY_DAYS; $day++) {
            $best = $eligible[0]['prices'][$day];
            $bestShopId = $eligible[0]['shop']->id;

            foreach ($eligible as $offer) {
                if ($offer['prices'][$day] < $best) {
                    $best = $offer['prices'][$day];
                    $bestShopId = $offer['shop']->id;
                }
            }

            $startedAt = now()->subDays(self::HISTORY_DAYS - 1 - $day)->setTime(6, 5);

            if ($openPrice !== null && abs($best - $openPrice) < 0.005 && $openShopId === $bestShopId) {
                continue;
            }

            if ($openStart !== null) {
                $segments[count($segments) - 1]['ended_at'] = $startedAt;
            }

            $segments[] = [
                'product_id' => $product->id,
                'cheapest_shop_id' => $bestShopId,
                'cheapest_price' => $best,
                'started_at' => $startedAt,
                'ended_at' => null,
                'triggering_price_check_id' => null,
            ];

            $openPrice = $best;
            $openShopId = $bestShopId;
            $openStart = $startedAt;
        }

        ProductCheapestHistory::query()->insert($segments);

        $last = $segments[count($segments) - 1];

        $product->forceFill([
            'cheapest_shop_id' => $last['cheapest_shop_id'],
            'cheapest_price' => $last['cheapest_price'],
        ])->save();
    }

    /**
     * A fired drop: the latch columns on the product, a `price_drop_events`
     * row and the database notification the bell and the dashboard widget
     * read. The payload keys mirror `PriceDropNotification::toDatabase()`.
     */
    private function seedDrop(User $user, Product $product, string $when): void
    {
        $firedAt = match ($when) {
            'today' => now()->subHours(3),
            'recent' => now()->subHours(20),
            default => now()->subDays(4),
        };

        $newPrice = (float) $product->cheapest_price;
        $reference = round($newPrice * 1.18, 2);
        $dropAbs = round($reference - $newPrice, 2);
        $dropPct = round($dropAbs / $reference * 100, 4);

        $priceCheckId = PriceCheck::query()
            ->where('shop_id', $product->cheapest_shop_id)
            ->orderByDesc('checked_at')
            ->value('id');

        $notificationId = (string) Str::uuid();
        $viewUrl = route('app.products.show', $product->id);

        $event = PriceDropEvent::query()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'price_check_id' => $priceCheckId,
            // Nothing in the app writes this column yet, so a demo row that
            // filled it would not match what a real drop leaves behind.
            'notification_id' => null,
            'triggered_by_shop_id' => $product->cheapest_shop_id,
            'currency' => $product->currency,
            'reference_price' => $reference,
            'reference_kind' => ReferenceValue::KIND_MEDIAN_30D,
            'new_price' => $newPrice,
            'drop_pct' => $dropPct,
            'drop_abs' => $dropAbs,
            'fired_at' => $firedAt,
        ]);

        // The latch holds the price that was alerted on — the dropped one,
        // exactly as `DetectDrop::triggerNotificationAtomically()` writes it.
        $product->forceFill([
            'last_notified_price' => $newPrice,
            'last_notified_at' => $firedAt,
        ])->save();

        $host = $product->cheapestShop?->host;

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => PriceDropNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'price_drop_event_id' => $event->id,
                'product_id' => $product->id,
                'title' => $product->title,
                'image_url' => $product->image_url,
                'currency' => $product->currency,
                'new_price' => number_format($newPrice, 2, '.', ''),
                'host' => $host,
                'offer_url' => $product->cheapestShop?->url,
                'reference_price' => number_format($reference, 2, '.', ''),
                'reference_kind' => ReferenceValue::KIND_MEDIAN_30D,
                'drop_percent' => (string) $dropPct,
                'drop_absolute' => number_format($dropAbs, 2, '.', ''),
                'view_url' => $viewUrl,
            ], JSON_THROW_ON_ERROR),
            // Older alerts read; the newest one still unread, so the bell
            // shows a count.
            'read_at' => $when === 'today' ? null : $firedAt->copy()->addHour(),
            'created_at' => $firedAt,
            'updated_at' => $firedAt,
        ]);

        $this->seedSavingsBackfill($user, $product, $event->price_check_id);
    }

    /**
     * Older drop events across the past year so the savings chart has bars
     * before this month. These carry no notification: the bell is for what
     * is current.
     */
    private function seedSavingsBackfill(User $user, Product $product, int $priceCheckId): void
    {
        for ($monthsAgo = 1; $monthsAgo <= 10; $monthsAgo += fake()->numberBetween(1, 2)) {
            $newPrice = round((float) $product->cheapest_price * fake()->randomFloat(2, 0.9, 1.1), 2);
            $reference = round($newPrice * fake()->randomFloat(2, 1.08, 1.30), 2);
            $dropAbs = round($reference - $newPrice, 2);

            PriceDropEvent::query()->create([
                'product_id' => $product->id,
                'user_id' => $user->id,
                'price_check_id' => $priceCheckId,
                'notification_id' => null,
                'triggered_by_shop_id' => $product->cheapest_shop_id,
                'currency' => $product->currency,
                'reference_price' => $reference,
                'reference_kind' => ReferenceValue::KIND_INITIAL,
                'new_price' => $newPrice,
                'drop_pct' => round($dropAbs / $reference * 100, 4),
                'drop_abs' => $dropAbs,
                'fired_at' => now()->subMonths($monthsAgo)->subDays(fake()->numberBetween(0, 20)),
            ]);
        }
    }

    private function adapterKey(string $host): string
    {
        return match ($host) {
            'ah.nl' => 'jsonld',
            'jumbo.com' => 'jumbo',
            'zooplus.nl' => 'zooplus',
            'dirk.nl' => 'dirk',
            default => 'jsonld',
        };
    }

    // ---------------------------------------------------------------------
    // Admin panel data
    // ---------------------------------------------------------------------

    /**
     * Stripe-shaped rows for the Subscribers and Disputes screens. Cashier
     * reads the local `subscriptions` table for plan state, so these render
     * without any call to Stripe. `RevenueOverviewWidget` still hides itself
     * until Stripe keys are configured — no seed can change that.
     */
    private function seedBilling(User $pro): void
    {
        $this->subscribe($pro, 'active');
        StripePayment::factory()->count(6)->create(['user_id' => $pro->id]);
    }

    private function subscribe(User $user, string $status, ?CarbonImmutable $endsAt = null, ?CarbonImmutable $trialEndsAt = null): void
    {
        $user->forceFill([
            'stripe_id' => 'cus_' . Str::random(14),
            'pm_type' => 'visa',
            'pm_last_four' => (string) fake()->numberBetween(1000, 9999),
        ])->save();

        Subscription::query()->create([
            'user_id' => $user->id,
            'type' => Plan::SUBSCRIPTION_TYPE,
            'stripe_id' => 'sub_' . Str::random(14),
            'stripe_status' => $status,
            'stripe_price' => 'price_demo_pro_monthly',
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * The other accounts an admin screen is meant to show: unverified, with
     * two-factor, on a trial, cancelling, past due, comped, and one blocked
     * after a lost chargeback.
     */
    private function seedCrowd(User $admin): void
    {
        User::factory()->admin()->create([
            'name' => 'Second Admin',
            'email' => 'admin2@dipcatch.test',
            'password' => self::PASSWORD,
        ]);

        User::factory()->count(6)->create([
            'created_at' => fn (): CarbonImmutable => CarbonImmutable::now()->subDays(fake()->numberBetween(1, 300)),
        ]);

        User::factory()->count(2)->unverified()->create();
        User::factory()->withTwoFactor()->create();

        $trialing = $this->createUser('trial@dipcatch.test', 'Tessa Trial', [
            'trial_ends_at' => now()->addDays(9),
        ]);
        $this->subscribe($trialing, 'trialing', trialEndsAt: CarbonImmutable::now()->addDays(9));

        $cancelling = $this->createUser('cancelling@dipcatch.test', 'Carla Cancelling');
        $this->subscribe($cancelling, 'active', endsAt: CarbonImmutable::now()->addDays(12));
        StripePayment::factory()->count(3)->create(['user_id' => $cancelling->id]);

        $pastDue = $this->createUser('pastdue@dipcatch.test', 'Paul Past-due');
        $this->subscribe($pastDue, 'past_due');
        StripePayment::factory()->create(['user_id' => $pastDue->id]);
        StripeDispute::factory()->create(['user_id' => $pastDue->id]);

        $blocked = $this->createUser('blocked@dipcatch.test', 'Bram Blocked', [
            'billing_blocked_at' => now()->subDays(6),
        ]);
        $this->subscribe($blocked, 'active');
        StripePayment::factory()->create(['user_id' => $blocked->id]);
        StripePayment::factory()->refund()->create(['user_id' => $blocked->id]);
        StripeDispute::factory()->lost()->create(['user_id' => $blocked->id]);

        $this->createUser('comped@dipcatch.test', 'Coen Comped', [
            'comped_until' => now()->addMonths(6),
            'comped_reason' => 'Beta tester',
        ]);

        $admin->forceFill(['email_verified_at' => now()])->save();
    }

    private function seedInvitations(User $admin): void
    {
        Invitation::factory()->count(4)->create(['invited_by' => $admin->id]);
        Invitation::factory()->count(2)->expired()->create(['invited_by' => $admin->id]);
        Invitation::factory()->count(3)->redeemed()->create(['invited_by' => $admin->id]);
    }
}
