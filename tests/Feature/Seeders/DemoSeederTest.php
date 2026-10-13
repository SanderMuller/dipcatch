<?php declare(strict_types=1);

use App\Billing\PlanLimits;
use App\Enums\CategorySource;
use App\Enums\ShopHealth;
use App\Filament\Admin\Widgets\OperationsOverviewWidget;
use App\Filament\Admin\Widgets\ShopsNeedingAttentionWidget;
use App\Livewire\Dashboard;
use App\Livewire\Notifications\Bell;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use Database\Seeders\DemoSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

// The seeder is the slow part, so each test checks a group of what it
// writes rather than one fact.
beforeEach(function (): void {
    $this->seed(DemoSeeder::class);
});

it('seeds the accounts and plans every walkthrough needs', function (): void {
    // Seeds the demo accounts.
    foreach (['demo@dipcatch.test', 'pro@dipcatch.test', 'free@dipcatch.test', 'newbie@dipcatch.test'] as $email) {
        expect(User::query()->where('email', $email)->exists())->toBeTrue();
    }

    expect(User::query()->where('is_admin', true)->exists())->toBeTrue()
        ->and(User::query()->count())->toBeGreaterThan(10);

    // Fills the demo account past one page of products.
    $demo = User::query()->where('email', 'demo@dipcatch.test')->sole();

    // The product list paginates at Laravel's default 15, so a walkthrough
    // only exercises paging, search and sorting above that.
    expect($demo->products()->count())->toBeGreaterThan(30)
        ->and($demo->products()->where('active', false)->count())->toBeGreaterThan(0)
        ->and($demo->products()->distinct()->count('title'))->toBe($demo->products()->count());

    // Leaves the free account one product short of its plan limit.
    $free = User::query()->where('email', 'free@dipcatch.test')->sole();
    $limit = $free->entitlements()->maxProducts();

    expect($limit)->not->toBeNull()
        ->and($free->products()->count())->toBe($limit - 1)
        ->and(app(PlanLimits::class)->canAddProduct($free))->toBeTrue();

    // Puts one free product at the plan shop ceiling.
    $ceiling = $free->entitlements()->maxShopsPerProduct();

    $offerCounts = $free->products()->withCount('shops')->get()->pluck('shops_count');

    expect($ceiling)->not->toBeNull()
        // Both ends of the shop limit: one product cannot take another offer,
        // and one still shows the "add a second shop" next step.
        ->and($offerCounts->max())->toBe($ceiling)
        ->and($offerCounts->min())->toBe(1);

    // Gives the admin account a populated app of its own.
    $admin = User::query()->where('is_admin', true)->orderBy('id')->firstOrFail();

    // Whoever seeds the database logs in as this account, so an app that
    // looks empty there reads as a seeder that did nothing.
    expect($admin->products()->count())->toBeGreaterThan(15);

    // Gives the ordinary accounts products of their own.
    $owners = Product::query()->distinct()->count('user_id');

    // Not just the four named demo accounts: the admin product list and the
    // per-account screens need more than one owner to be worth looking at.
    expect($owners)->toBeGreaterThan(6);

    // Comps the developer account for good and opts it in to automatic categories.
    expect($admin->isComped())->toBeTrue()
        ->and($admin->comped_until?->toDateString())->toBe('2099-12-31')
        ->and($admin->comped_reason)->toBe('Developer account')
        ->and($admin->auto_categories)->toBeTrue();

    // Seeds invitations and billing rows for the admin panel.
    expect(DB::table('invitations')->whereNull('redeemed_at')->where('expires_at', '>', now())->count())->toBeGreaterThan(0)
        ->and(DB::table('invitations')->whereNotNull('redeemed_at')->count())->toBeGreaterThan(0)
        ->and(DB::table('invitations')->where('expires_at', '<', now())->count())->toBeGreaterThan(0)
        ->and(DB::table('subscriptions')->where('stripe_status', 'active')->count())->toBeGreaterThan(0)
        ->and(DB::table('subscriptions')->where('stripe_status', 'trialing')->count())->toBeGreaterThan(0)
        ->and(DB::table('stripe_payments')->count())->toBeGreaterThan(0)
        ->and(DB::table('stripe_disputes')->count())->toBeGreaterThan(0);
});

it('seeds prices, history, drops and notifications that agree with each other', function (): void {
    // Gives the demo user products, offers and price history.
    $demo = User::query()->where('email', 'demo@dipcatch.test')->sole();

    expect($demo->products()->count())->toBeGreaterThanOrEqual(8)
        ->and(Shop::query()->whereIn('product_id', $demo->products()->pluck('id'))->count())->toBeGreaterThanOrEqual(15)
        ->and(PriceCheck::query()->count())->toBeGreaterThan(500);

    // Sorts most seeded products into a category from the taxonomy and leaves some unsorted.
    $products = $demo->products()->get();
    $sorted = $products->filter(fn (Product $product): bool => $product->category !== null);

    expect($sorted->count())->toBeGreaterThan($products->count() / 2)
        ->and($products->count() - $sorted->count())->toBeGreaterThan(0)
        ->and($sorted->pluck('category_set_by')->unique()->all())->toContain(CategorySource::User, CategorySource::Auto)
        ->and($products->whereNull('category')->pluck('category_set_by')->filter()->all())->toBeEmpty()
        ->and($sorted->map(fn (Product $product): string => $product->category?->department()->value ?? '')->unique()->count())->toBeGreaterThan(3)
        ->and($demo->auto_categories)->toBeTrue()
        ->and(User::query()->where('email', 'pro@dipcatch.test')->sole()->auto_categories)->toBeTrue()
        ->and(User::query()->where('email', 'free@dipcatch.test')->sole()->auto_categories)->toBeFalse();

    // Points every product at one of its own offers.
    Product::query()->whereNotNull('cheapest_shop_id')->with('shops')->each(function (Product $product): void {
        expect($product->shops->pluck('id'))->toContain($product->cheapest_shop_id)
            ->and($product->cheapest_price)->not->toBeNull();
    });

    // Writes cheapest history segments with a single open one per product.
    Product::query()->whereNotNull('cheapest_shop_id')->each(function (Product $product): void {
        expect($product->cheapestHistory()->count())->toBeGreaterThan(0)
            ->and($product->cheapestHistory()->whereNull('ended_at')->count())->toBe(1);
    });

    // Shows active drops below the notified price.
    $dropped = Product::query()->whereNotNull('last_notified_price')->get();

    expect($dropped)->not->toBeEmpty();

    foreach ($dropped as $product) {
        expect((float) $product->cheapest_price)->toBeLessThanOrEqual((float) $product->last_notified_price)
            ->and($product->last_notified_at)->not->toBeNull();
    }

    // Fires drop events in the last day, week and year.
    expect(PriceDropEvent::query()->where('fired_at', '>=', now()->subDay())->count())->toBeGreaterThan(0)
        ->and(PriceDropEvent::query()->where('fired_at', '>=', now()->subWeek())->count())->toBeGreaterThan(1)
        ->and(PriceDropEvent::query()->where('fired_at', '<', now()->subMonth())->count())->toBeGreaterThan(0);

    // Writes database notifications the dashboard widget can render.
    $rows = DatabaseNotification::query()->where('type', PriceDropNotification::class)->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row->data)->toHaveKeys(['title', 'drop_percent', 'drop_absolute', 'currency', 'product_id', 'new_price']);
    }

    // Records checks that agree with what the offer says about stock.
    Shop::query()->with('priceChecks')->each(function (Shop $shop): void {
        $stockAnswers = $shop->priceChecks
            ->whereNotNull('price')
            ->pluck('in_stock')
            ->unique();

        expect($stockAnswers)->toHaveCount(1)
            ->and($stockAnswers->first())->toBe($shop->current_in_stock);
    });

    // Leaves offers in every health state for the admin dashboard.
    foreach ([ShopHealth::Ok, ShopHealth::Failing, ShopHealth::Dead] as $health) {
        expect(Shop::query()->where('health', $health->value)->exists())->toBeTrue();
    }

    expect(Shop::query()->where('current_in_stock', false)->exists())->toBeTrue()
        ->and(Shop::query()->whereNull('current_in_stock')->exists())->toBeTrue();
});

it('seeds the real catalog with pages, photos and a per-unit comparison that work', function (): void {
    // The real catalog points at pages that exist and photos that load.
    // Every other catalog here is invented, so a recheck reads a 404 and no
    // image ever loads. These were read through DipCatch's own probe, and a
    // developer needs at least one account where a check does what it does in
    // production.

    $roter = Product::query()->where('title', 'Roter Vitamine C 70 mg citroen kauwtabletten')->first();

    expect($roter)->not->toBeNull()
        ->and($roter?->image_url)->toStartWith('https://static.ah.nl/');

    $roter = Product::query()->where('title', 'Roter Vitamine C 70 mg citroen kauwtabletten')->firstOrFail();
    $hosts = $roter->shops->pluck('url', 'host')->all();

    expect($hosts)->toHaveKeys(['ah.nl', 'benushop.nl'])
        ->and($hosts['ah.nl'])->toBe('https://www.ah.nl/producten/product/wi56116/roter-vitamine-c-70-mg-kauwtabletten-citroen');

    // Every shop on a real product carries the photo its own page serves.
    foreach ($roter->shops as $shop) {
        expect($shop->safeImageUrl())->not->toBeNull();
    }

    // The two Roter packs are the per-unit comparison, live in the demo.
    // 12.99 for 400 tablets is 0.0325 each; 21.99 for 800 is 0.0275. Both
    // rendered 0.03 until the ranking stopped comparing the display figure.

    expect($roter->bestValueShop()?->host)->toBe('benushop.nl');

    // The seeded history carries the best value from its first day.
    // Without it the per-unit chart line began at the first live check, and
    // the chart opened on one dot at its right edge.
    $first = $roter->cheapestHistory()->oldest('started_at')->firstOrFail();

    expect($first->best_value_shop_id)->not->toBeNull()
        ->and($first->best_value_price)->not->toBeNull()
        ->and($first->pack_unit)->toBe('piece');

    // A product with no photo of its own names itself in the stand-in.
    // Better than an empty frame, and better than showing some other
    // product's picture.

    $generated = Product::query()
        ->where('image_url', 'like', 'https://placehold.co/%')
        ->first();

    expect($generated)->not->toBeNull()
        ->and($generated?->image_url)->toContain(rawurlencode((string) $generated?->title));
});

it('does not seed twice', function (): void {
    $before = User::query()->count();

    $this->seed(DemoSeeder::class);

    expect(User::query()->count())->toBe($before);
});

it('renders the admin dashboard with the seeded data', function (): void {
    $admin = User::query()->where('is_admin', true)->orderBy('id')->firstOrFail();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        // The operations widget counts accounts, offers and drops; the
        // attention list names the hosts whose offers stopped reading.
        ->assertSee('Accounts');

    // The table widgets load lazily, so the dashboard HTML alone does not
    // prove they have rows — render them directly.
    $this->actingAs($admin);

    livewire(OperationsOverviewWidget::class)
        ->assertSee('Accounts')
        ->assertSee('Scrapes healthy');

    livewire(ShopsNeedingAttentionWidget::class)
        ->assertSee('Offers needing attention')
        ->assertSee('bol.com');
});

it('renders the app dashboard with the seeded data', function (): void {
    $demo = User::query()->where('email', 'demo@dipcatch.test')->sole();

    $this->actingAs($demo)
        ->get('/app')
        ->assertOk()
        ->assertSee('Tracked products');

    livewire(Dashboard::class)
        ->assertSee('Douwe Egberts Aroma Rood koffiebonen 1 kg');

    livewire(Bell::class)
        ->assertSee('Zeeuws Meisje Roomboter 250 g');
});

it('renders a seeded product page with its price history', function (): void {
    $demo = User::query()->where('email', 'demo@dipcatch.test')->sole();
    $product = $demo->products()->where('title', 'Douwe Egberts Aroma Rood koffiebonen 1 kg')->sole();

    $this->actingAs($demo)
        ->get('/app/products/' . $product->id)
        ->assertOk()
        ->assertSee('jumbo.com');
});
