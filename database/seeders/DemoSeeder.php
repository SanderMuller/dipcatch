<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Plan;
use App\Enums\CategorySource;
use App\Enums\ProductCategory;
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
use Database\Seeders\Demo\DatasetCatalog;
use Database\Seeders\Demo\DemoBestValue;
use Database\Seeders\Demo\DemoOffer;
use Database\Seeders\Demo\DemoProduct;
use Database\Seeders\Demo\GeneratedCatalog;
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

    /** Days of price history written per offer, unless the spec asks for fewer. */
    private const int HISTORY_DAYS = 75;

    /**
     * Generated products on top of the curated catalogs. The demo account is
     * the one a walkthrough uses, so it gets enough to page, search and sort
     * through; the free account stops one short of `plans.free.max_products`,
     * so the limit counter shows a number and the add button still works.
     */
    private const int DEMO_GENERATED = 38;

    private const int PRO_GENERATED = 27;

    private const int FREE_GENERATED = 17;

    /** Products per ordinary account, so the admin panel lists many owners. */
    private const int CROWD_GENERATED = 4;

    /**
     * The admin account is the one whoever seeds this database logs in as, so
     * it gets a populated app of its own rather than the single sample product
     * `ZooplusFeliwaySeeder` leaves on it.
     */
    private const int ADMIN_GENERATED = 30;

    public function run(): void
    {
        // A fresh walk of the price dataset: the resolver remembers what the
        // AH API answered so five accounts ask it once, and that memory must
        // not outlive the seed that filled it.
        DatasetCatalog::forget();

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
            'auto_categories' => true,
        ]);

        $pro = $this->createUser(self::PRO_EMAIL, 'Petra Pro', ['auto_categories' => true]);
        $free = $this->createUser(self::FREE_EMAIL, 'Frank Free');
        $this->createUser(self::EMPTY_EMAIL, 'Nina Newbie');

        // Offset past the demo account's slice, so the two do not track the
        // same list of products.
        $this->seedCatalog($admin, $this->realCatalog());
        $this->seedCatalog($admin, GeneratedCatalog::make(self::ADMIN_GENERATED, offset: self::DEMO_GENERATED));

        $this->seedCatalog($demo, $this->realCatalog());
        $this->seedCatalog($demo, $this->demoCatalog());
        $this->seedCatalog($demo, GeneratedCatalog::make(self::DEMO_GENERATED));

        $this->seedCatalog($pro, $this->proCatalog());
        $this->seedCatalog($pro, GeneratedCatalog::make(self::PRO_GENERATED));

        $this->seedCatalog($free, $this->freeCatalog());
        $this->seedCatalog($free, GeneratedCatalog::make(self::FREE_GENERATED));

        $this->seedBilling($pro);
        $this->seedCrowd($admin);
        $this->seedInvitations($admin);

        $this->command->info('DemoSeeder: demo data seeded — ' . Product::query()->count() . ' products across ' . User::query()->count() . ' accounts.');
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
                $admin->forceFill(self::developerPerks())->save();

                return $admin;
            }
        }

        return $this->createUser(self::FALLBACK_ADMIN_EMAIL, 'Demo Admin', ['is_admin' => true, ...self::developerPerks()]);
    }

    /**
     * Only here, never in `AdminUserSeeder`: this seeder does not run in production.
     *
     * @return array<string, mixed>
     */
    private static function developerPerks(): array
    {
        return [
            'comped_until' => Plan::COMPED_FOREVER,
            'comped_reason' => 'Developer account',
            'auto_categories' => true,
        ];
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
     * Products that exist, at addresses that answer.
     *
     * Every other catalog here is invented: plausible titles on invented
     * paths, which is enough to fill a list and nothing more. A recheck of one
     * reads a 404, so no price ever moves, no image ever loads, and the parts
     * of the app that only work against a real page — the adapter chain, the
     * unit comparison, the image — cannot be exercised at all without adding a
     * shop by hand first.
     *
     * These were read through DipCatch's own probe on 2026-09-22, the pizza
     * and the toothpaste on 2026-09-26, and the prices, pack sizes and photos
     * below are what came back. Re-running a check on a seeded account now
     * does what it does in production.
     *
     * Two of them earn their place twice. The Roter pair is the same tablet in
     * a 400-pack and an 800-pack: 0.0325 against 0.0275 each, an 18% gap that
     * both rendered as EUR 0.03 until this morning, so it is the per-unit
     * ranking and the four-decimal display in one product. The AURMOO box is a
     * title counting two hundred bags of five litres, which is the pack-count
     * reading that used to price one bag's worth of plastic as the whole box.
     *
     * A price here ages. When one drifts far enough to bother a reader, probe
     * the URL again and paste back what it says rather than guessing.
     *
     * @return list<DemoProduct>
     */
    private function realCatalog(): array
    {
        return [
            new DemoProduct(
                title: 'Roter Vitamine C 70 mg citroen kauwtabletten',
                category: ProductCategory::MedicinesSupplements,
                unitPriceTarget: 0.0280,
                offers: [
                    new DemoOffer(
                        host: 'ah.nl', path: '', price: 12.99, packQuantity: 400, packUnit: 'piece',
                        realUrl: 'https://www.ah.nl/producten/product/wi56116/roter-vitamine-c-70-mg-kauwtabletten-citroen',
                        imageUrl: 'https://static.ah.nl/dam/product/AHI_41565f74504d4f34527a476c66355452435138536541?revLabel=1&rendition=800x800_WEBP&fileType=binary',
                    ),
                    new DemoOffer(
                        host: 'benushop.nl', path: '', price: 21.99, packQuantity: 800, packUnit: 'piece',
                        realUrl: 'https://www.benushop.nl/apotheek/vitaminen/vitamine-c/roter-voordeelverpakking-vitamine-c-70mg-citroen-kauwtabletten-800-stuks',
                        imageUrl: 'https://www.benushop.nl/images/productimages/big/8713304941826_1.jpg',
                    ),
                ],
            ),
            new DemoProduct(
                title: 'Creapure Creatine 500 g',
                category: ProductCategory::MedicinesSupplements,
                offers: [
                    new DemoOffer(
                        host: 'bodyandfit.com', path: '', price: 29.99, packQuantity: 500, packUnit: 'g',
                        realUrl: 'https://www.bodyandfit.com/en/products/creapure-creatine',
                        imageUrl: 'https://www.bodyandfit.com/cdn/shop/files/01668_Image_01_79c5e785-834b-4215-a613-222613b78856.png?v=1783689694&width=1920',
                    ),
                ],
            ),
            new DemoProduct(
                title: 'AURMOO Vuilniszakken 5 L (200 stuks)',
                category: ProductCategory::PaperDisposables,
                offers: [
                    new DemoOffer(
                        host: 'amazon.nl', path: '', price: 15.99, packQuantity: 1000000, packUnit: 'ml',
                        realUrl: 'https://www.amazon.nl/AURMOO-Vuilniszakken-Afbreekbaar-Vuilniszak-M%C3%BCllbeutel/dp/B0BHZJYGY5',
                        imageUrl: 'https://m.media-amazon.com/images/I/51np1PVT2+L.jpg',
                    ),
                ],
            ),
            new DemoProduct(
                title: 'Fanta Cassis 1,5 L',
                category: ProductCategory::SoftDrinks,
                offers: [
                    new DemoOffer(
                        host: 'spar.nl', path: '', price: 3.19, packQuantity: 1500, packUnit: 'ml',
                        realUrl: 'https://www.spar.nl/fanta-fanta-cassis-pet-1.5l-9256413/',
                        imageUrl: 'https://media.spar.nl/productdetail/fanta-fanta-cassis-pet-1.5l-1.5-Liter-9256413-168619.jpg',
                    ),
                ],
            ),
            new DemoProduct(
                title: 'Feliway Classic Startpakket verdamper',
                category: ProductCategory::PetCare,
                offers: [
                    new DemoOffer(
                        host: 'zooplus.nl', path: '', price: 27.99, packQuantity: 1, packUnit: 'piece',
                        realUrl: 'https://www.zooplus.nl/shop/katten/verzorging/huisapotheek/verdamper/169589?activeVariant=169589.10',
                        imageUrl: 'https://media.zooplus.com/bilder/9/400/67609_pla_ceva_feliway_classic_hs_01_9.jpg',
                    ),
                ],
            ),
            new DemoProduct(
                title: 'Vet-Concept Cat Sana Paard 3 kg',
                category: ProductCategory::PetFood,
                offers: [
                    new DemoOffer(
                        host: 'dierapotheker.nl', path: '', price: 30.55, packQuantity: 3000, packUnit: 'g',
                        realUrl: 'https://www.dierapotheker.nl/vet-concept-sana-paard-kattenvoer/9271/',
                        imageUrl: 'https://www.dierapotheker.nl/media/c1/2d/15/1725524946/Vet-Concept-Sana-Paard-Kattenvoer-10-kg.jpg',
                    ),
                ],
            ),
            new DemoProduct(
                title: 'Dr. Oetker Big Americans Pizza Texas 435 g',
                category: ProductCategory::Frozen,
                offers: [
                    // Dirk's offer price, 23 to 29 September 2026. The normal price is 4.65.
                    new DemoOffer(
                        host: 'dirk.nl', path: '', price: 1.89, packQuantity: 435, packUnit: 'g',
                        realUrl: 'https://www.dirk.nl/boodschappen/diepvries/diepvries-pizzas-maaltijden/dr-oetker-big-americans-pizza-texas/68429',
                        imageUrl: 'https://web-fileserver.dirk.nl/artikelen/219747_1_421876_638771973556937168.png?width=500&height=500&mode=crop',
                    ),
                    new DemoOffer(
                        host: 'jumbo.com', path: '', price: 4.95, packQuantity: 435, packUnit: 'g',
                        realUrl: 'https://www.jumbo.com/producten/dr-oetker-big-americans-pizza-texas-435-g-184179DS',
                        imageUrl: 'https://www.jumbo.com/dam-images/fit-in/360x360/Products/17042025_1744858686454_1744858693343_184179_DS_04001724023906_C1N1_s02.png',
                    ),
                ],
            ),
            new DemoProduct(
                title: 'Sensodyne Rapid Relief Mint tandpasta 75 ml',
                category: ProductCategory::Oral,
                offers: [
                    new DemoOffer(
                        host: 'jumbo.com', path: '', price: 7.29, packQuantity: 75, packUnit: 'ml',
                        realUrl: 'https://www.jumbo.com/producten/sensodyne-rapid-relief-mint-tandpasta-75-ml-705075DS',
                        imageUrl: 'https://www.jumbo.com/dam-images/fit-in/360x360/Products/5054563235404_1788998419372_fmd1ih0j2knryphuqmte.png',
                    ),
                    new DemoOffer(
                        host: 'deonlinedrogist.nl', path: '', price: 7.14, packQuantity: 75, packUnit: 'ml',
                        realUrl: 'https://www.deonlinedrogist.nl/drogist/sensodyne-rapid-relief-tandpasta-75ml.htm',
                        imageUrl: 'https://img.deonlinedrogist.nl/wjGOPaliQKXMLdrbhUuqk_GUxeaiBiLhhaXcxesUGI4/dpr:1/bg:FFFFFF/fn:sensodyne-rapid-relief-tandpasta-75ml/plain/s3://dod-storage/media/40/18/81f3945ad31198780d5398bd6c395755.png',
                    ),
                ],
            ),
        ];
    }

    /**
     * @return list<DemoProduct>
     */
    private function demoCatalog(): array
    {
        return [
            new DemoProduct(
                title: 'Douwe Egberts Aroma Rood koffiebonen 1 kg',
                category: ProductCategory::CoffeeTea,
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
                categorySource: CategorySource::Auto,
                category: ProductCategory::DairyEggs,
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi222333/zeeuws-meisje-roomboter', 3.29, 250, 'g'),
                    new DemoOffer('jumbo.com', 'producten/zeeuws-meisje-roomboter-250g', 3.09, 250, 'g'),
                    new DemoOffer('dirk.nl', 'boodschappen/zuivel/boter/zeeuws-meisje-roomboter/1234', 2.89, 250, 'g'),
                ],
                drop: 'today',
            ),
            new DemoProduct(
                title: 'Coca-Cola Zero Sugar 6 x 1,5 L',
                category: ProductCategory::SoftDrinks,
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi334455/coca-cola-zero-6-pack', 11.94, 9000, 'ml'),
                    new DemoOffer('jumbo.com', 'producten/coca-cola-zero-sugar-6x1-5l', 10.99, 9000, 'ml', promotion: 'live'),
                ],
            ),
            new DemoProduct(
                title: 'Pampers Baby-Dry maat 4 (174 stuks)',
                category: ProductCategory::NappiesWipes,
                offers: [
                    new DemoOffer('bol.com', 'nl/nl/p/pampers-baby-dry-maat-4-174-luiers/9200000098765432', 44.99, 174, 'piece'),
                    new DemoOffer('amazon.nl', 'dp/B08PAMPERS4', 41.95, 174, 'piece'),
                ],
                drop: 'week',
            ),
            new DemoProduct(
                title: 'Whiskas Adult kattenvoer 100 zakjes',
                category: ProductCategory::PetFood,
                offers: [
                    new DemoOffer('zooplus.nl', 'shop/katten/kattenvoer_nat/whiskas/100-zakjes/123456', 39.99, 100, 'piece'),
                    new DemoOffer('bol.com', 'nl/nl/p/whiskas-adult-100-zakjes/9200000011112222', 43.50, 100, 'piece', state: 'out_of_stock'),
                ],
            ),
            new DemoProduct(
                title: 'Philips Hue White and Color Ambiance E27 (2-pack)',
                category: ProductCategory::SmartHome,
                offers: [
                    new DemoOffer('coolblue.nl', 'product/912345/philips-hue-white-and-color-e27-duopack.html', 89.00, 2, 'piece'),
                    new DemoOffer('bol.com', 'nl/nl/p/philips-hue-white-and-color-e27-2-pack/9200000033334444', 94.99, 2, 'piece', state: 'unknown_stock'),
                ],
                drop: 'week',
            ),
            new DemoProduct(
                title: 'Nespresso Vertuo Barista Creations (30 capsules)',
                category: ProductCategory::CoffeeTea,
                offers: [
                    new DemoOffer('bol.com', 'nl/nl/p/nespresso-vertuo-barista-30-capsules/9200000055556666', 21.45, 30, 'piece', state: 'failing'),
                    new DemoOffer('amazon.nl', 'dp/B08VERTUO30', 19.99, 30, 'piece'),
                ],
            ),
            new DemoProduct(
                title: 'Grolsch Premium Pilsner 24 x 30 cl',
                categorySource: CategorySource::Auto,
                category: ProductCategory::Alcohol,
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi556677/grolsch-premium-pilsner-24-pack', 18.99, 7200, 'ml', conditional: true),
                    new DemoOffer('jumbo.com', 'producten/grolsch-premium-pilsner-24x30cl', 17.49, 7200, 'ml', promotion: 'expired'),
                ],
            ),
            new DemoProduct(
                title: 'Garmin Forerunner 265',
                category: ProductCategory::Wearables,
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
                category: ProductCategory::Audio,
                offers: [
                    new DemoOffer('coolblue.nl', 'product/901234/sony-wh-1000xm5-zwart.html', 299.00, 1, 'piece'),
                    new DemoOffer('bol.com', 'nl/nl/p/sony-wh-1000xm5/9200000044445555', 319.00, 1, 'piece'),
                    new DemoOffer('amazon.nl', 'dp/B09XM5SONY', 289.99, 1, 'piece'),
                ],
                drop: 'recent',
            ),
            new DemoProduct(
                title: 'Lay\'s Oven Baked Paprika 150 g',
                categorySource: CategorySource::Auto,
                category: ProductCategory::SnacksSweets,
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi667788/lays-oven-baked-paprika', 2.19, 150, 'g'),
                    new DemoOffer('dirk.nl', 'boodschappen/snacks/chips/lays-oven-baked/5678', 1.99, 150, 'g'),
                ],
            ),
            new DemoProduct(
                title: 'Royal Canin Medium Adult 15 kg',
                category: ProductCategory::PetFood,
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
                category: ProductCategory::Laundry,
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi889900/robijn-color-wasmiddel', 9.99, 40, 'piece'),
                ],
            ),
            new DemoProduct(
                // Offers up to `plans.free.max_shops_per_product`, so the
                // shop-limit panel and the upgrade path it offers render on
                // an account that has genuinely reached the ceiling.
                title: 'Tony\'s Chocolonely Melk 180 g',
                category: ProductCategory::SnacksSweets,
                offers: [
                    new DemoOffer('ah.nl', 'producten/product/wi990011/tonys-chocolonely-melk', 3.49, 180, 'g'),
                    new DemoOffer('jumbo.com', 'producten/tonys-chocolonely-melk-180g', 3.19, 180, 'g'),
                    new DemoOffer('dirk.nl', 'boodschappen/snoep/chocolade/tonys-chocolonely-melk/9012', 2.99, 180, 'g'),
                    new DemoOffer('bol.com', 'nl/nl/p/tonys-chocolonely-melk-180-g/9200000022223333', 3.75, 180, 'g'),
                ],
            ),
        ];
    }

    // ---------------------------------------------------------------------
    // Product construction
    // ---------------------------------------------------------------------

    /**
     * @param  list<DemoProduct>  $catalog
     */
    private function seedCatalog(User $user, array $catalog): void
    {
        foreach ($catalog as $spec) {
            $this->seedProduct($user, $spec);
        }
    }

    private function seedProduct(User $user, DemoProduct $spec): void
    {
        $historyDays = $spec->historyDays ?? self::HISTORY_DAYS;
        $ageDays = max($spec->ageDays ?? $historyDays + 5, $historyDays + 5);

        $product = Product::factory()->create([
            'user_id' => $user->id,
            'title' => $spec->title,
            // The photo the first offer that has one serves, exactly as a
            // real add does: a product page with an empty frame is the first
            // thing anyone notices about a seeded account, and the factory's
            // own placeholder points at via.placeholder.com, which no longer
            // resolves.
            'image_url' => $spec->image(),
            'currency' => 'EUR',
            'drop_threshold_pct' => 5.00,
            'drop_threshold_abs' => 0.50,
            'unit_price_target' => $spec->unitPriceTarget,
            'share_slug' => $spec->shareSlug,
            'active' => $spec->active,
            'category' => $spec->category,
            'category_set_by' => $spec->category === null ? null : $spec->categorySource,
            'created_at' => now()->subDays($ageDays),
        ]);

        /** @var list<array{shop: Shop, prices: array<int, float>}> $offers */
        $offers = [];

        foreach ($spec->offers as $offer) {
            $offers[] = $this->seedShop($product, $offer, $historyDays, $ageDays);
        }

        $this->seedCheapestHistory($product, $offers, $historyDays);

        // A drop needs a price to have dropped to. A product whose offers are
        // all ineligible has no cheapest pointer, so there is nothing to fire.
        if ($spec->drop !== null && $product->cheapest_price !== null) {
            $this->seedDrop($user, $product, $spec->drop);
        }
    }

    /**
     * @return array{shop: Shop, prices: array<int, float>}
     */
    private function seedShop(Product $product, DemoOffer $offer, int $historyDays, int $ageDays): array
    {
        $prices = $this->priceWalk($offer->price, $historyDays);

        $createdAt = now()->subDays($ageDays - 1);

        $shop = Shop::factory()->create([
            'product_id' => $product->id,
            'url' => $offer->url(),
            'image_url' => $offer->imageUrl,
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

        $this->seedPriceChecks($shop, $prices, $offer->state, $shop->current_in_stock, $historyDays);

        return ['shop' => $shop, 'prices' => $prices];
    }

    /**
     * A price path ending on the offer's current price: it starts higher, so
     * the chart has a shape and the 30-day median sits above today.
     *
     * @return array<int, float> index 0 = oldest day, last = today
     */
    private function priceWalk(float $current, int $historyDays): array
    {
        $prices = [];

        for ($day = 0; $day < $historyDays; $day++) {
            $progress = $day / ($historyDays - 1);
            $drift = 1.0 + 0.14 * (1 - $progress);
            $noise = 1.0 + fake()->randomFloat(4, -0.02, 0.02);
            $prices[] = round($current * $drift * $noise, 2);
        }

        $prices[$historyDays - 1] = $current;

        return $prices;
    }

    /**
     * @param  array<int, float>  $prices
     * @param  bool|null  $inStock  What the offer records today: the checks
     *                              must agree with it, or the offer reads as
     *                              sold out while its last check says stocked.
     */
    private function seedPriceChecks(Shop $shop, array $prices, string $state, ?bool $inStock, int $historyDays): void
    {
        $rows = [];

        foreach ($prices as $day => $price) {
            $checkedAt = now()->subDays($historyDays - 1 - $day)->setTime(6, 0);

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
    private function seedCheapestHistory(Product $product, array $offers, int $historyDays): void
    {
        $eligible = array_values(array_filter(
            $offers,
            static fn (array $offer): bool => $offer['shop']->health !== ShopHealth::Dead
                && $offer['shop']->current_in_stock !== false,
        ));

        if ($eligible === []) {
            return;
        }

        // The best value per day too, as recomputeCheapestShop() writes it, so
        // the per-unit chart line spans the history. Only the segments get it:
        // the seeded drops are pack-price drops, and a best value on the
        // product row would move them to the unit basis and hide them.
        $packs = $product->load('shops')->comparablePacks();

        $segments = [];
        $openPrice = null;
        $openShopId = null;
        $openBestValue = null;
        $openStart = null;

        for ($day = 0; $day < $historyDays; $day++) {
            $best = $eligible[0]['prices'][$day];
            $bestShopId = $eligible[0]['shop']->id;

            foreach ($eligible as $offer) {
                if ($offer['prices'][$day] < $best) {
                    $best = $offer['prices'][$day];
                    $bestShopId = $offer['shop']->id;
                }
            }

            $bestValue = DemoBestValue::on($eligible, $packs, $day);
            $startedAt = now()->subDays($historyDays - 1 - $day)->setTime(6, 5);

            if ($openPrice !== null && abs($best - $openPrice) < 0.005 && $openShopId === $bestShopId && DemoBestValue::same($bestValue, $openBestValue)) {
                continue;
            }

            if ($openStart !== null) {
                $segments[count($segments) - 1]['ended_at'] = $startedAt;
            }

            $segments[] = [
                'product_id' => $product->id,
                'cheapest_shop_id' => $bestShopId,
                'cheapest_price' => $best,
                'best_value_shop_id' => $bestValue?->shop->id,
                'best_value_price' => $bestValue?->price,
                'pack_quantity' => $bestValue?->size->quantity,
                'pack_unit' => $bestValue?->size->unit,
                'started_at' => $startedAt,
                'ended_at' => null,
                'triggering_price_check_id' => null,
            ];

            $openPrice = $best;
            $openShopId = $bestShopId;
            $openBestValue = $bestValue;
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
     * after a lost chargeback. The plain accounts track products of their own,
     * so the admin product and offer screens list more than four owners.
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
        ])->each(function (User $user, int $index): void {
            // A different slice of the pool per account, so the admin product
            // list does not read as the same product repeated per owner.
            $this->seedCatalog($user, GeneratedCatalog::make(
                self::CROWD_GENERATED,
                offset: $index * self::CROWD_GENERATED,
            ));
        });

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
