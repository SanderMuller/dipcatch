<?php declare(strict_types=1);

use App\Enums\ShopHealth;
use App\Filament\Admin\Widgets\OperationsOverviewWidget;
use App\Filament\Admin\Widgets\ShopsNeedingAttentionWidget;
use App\Filament\App\Widgets\ActiveDropsTableWidget;
use App\Filament\App\Widgets\RecentNotificationsTableWidget;
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

beforeEach(function (): void {
    $this->seed(DemoSeeder::class);
});

it('seeds the demo accounts', function (): void {
    foreach (['demo@dipcatch.test', 'pro@dipcatch.test', 'free@dipcatch.test', 'newbie@dipcatch.test'] as $email) {
        expect(User::query()->where('email', $email)->exists())->toBeTrue();
    }

    expect(User::query()->where('is_admin', true)->exists())->toBeTrue()
        ->and(User::query()->count())->toBeGreaterThan(10);
});

it('gives the demo user products, offers and price history', function (): void {
    $demo = User::query()->where('email', 'demo@dipcatch.test')->sole();

    expect($demo->products()->count())->toBeGreaterThanOrEqual(8)
        ->and(Shop::query()->whereIn('product_id', $demo->products()->pluck('id'))->count())->toBeGreaterThanOrEqual(15)
        ->and(PriceCheck::query()->count())->toBeGreaterThan(500);
});

it('points every product at one of its own offers', function (): void {
    Product::query()->whereNotNull('cheapest_shop_id')->with('shops')->each(function (Product $product): void {
        expect($product->shops->pluck('id'))->toContain($product->cheapest_shop_id)
            ->and($product->cheapest_price)->not->toBeNull();
    });
});

it('writes cheapest history segments with a single open one per product', function (): void {
    Product::query()->whereNotNull('cheapest_shop_id')->each(function (Product $product): void {
        expect($product->cheapestHistory()->count())->toBeGreaterThan(0)
            ->and($product->cheapestHistory()->whereNull('ended_at')->count())->toBe(1);
    });
});

it('shows active drops below the notified price', function (): void {
    $dropped = Product::query()->whereNotNull('last_notified_price')->get();

    expect($dropped)->not->toBeEmpty();

    foreach ($dropped as $product) {
        expect((float) $product->cheapest_price)->toBeLessThanOrEqual((float) $product->last_notified_price)
            ->and($product->last_notified_at)->not->toBeNull();
    }
});

it('fires drop events in the last day, week and year', function (): void {
    expect(PriceDropEvent::query()->where('fired_at', '>=', now()->subDay())->count())->toBeGreaterThan(0)
        ->and(PriceDropEvent::query()->where('fired_at', '>=', now()->subWeek())->count())->toBeGreaterThan(1)
        ->and(PriceDropEvent::query()->where('fired_at', '<', now()->subMonth())->count())->toBeGreaterThan(0);
});

it('writes database notifications the dashboard widget can render', function (): void {
    $rows = DatabaseNotification::query()->where('type', PriceDropNotification::class)->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row->data)->toHaveKeys(['title', 'drop_percent', 'drop_absolute', 'currency', 'product_id', 'new_price']);
    }
});

it('records checks that agree with what the offer says about stock', function (): void {
    Shop::query()->with('priceChecks')->each(function (Shop $shop): void {
        $stockAnswers = $shop->priceChecks
            ->whereNotNull('price')
            ->pluck('in_stock')
            ->unique();

        expect($stockAnswers)->toHaveCount(1)
            ->and($stockAnswers->first())->toBe($shop->current_in_stock);
    });
});

it('leaves offers in every health state for the admin dashboard', function (): void {
    foreach ([ShopHealth::Ok, ShopHealth::Failing, ShopHealth::Dead] as $health) {
        expect(Shop::query()->where('health', $health->value)->exists())->toBeTrue();
    }

    expect(Shop::query()->where('current_in_stock', false)->exists())->toBeTrue()
        ->and(Shop::query()->whereNull('current_in_stock')->exists())->toBeTrue();
});

it('seeds invitations and billing rows for the admin panel', function (): void {
    expect(DB::table('invitations')->whereNull('redeemed_at')->where('expires_at', '>', now())->count())->toBeGreaterThan(0)
        ->and(DB::table('invitations')->whereNotNull('redeemed_at')->count())->toBeGreaterThan(0)
        ->and(DB::table('invitations')->where('expires_at', '<', now())->count())->toBeGreaterThan(0)
        ->and(DB::table('subscriptions')->where('stripe_status', 'active')->count())->toBeGreaterThan(0)
        ->and(DB::table('subscriptions')->where('stripe_status', 'trialing')->count())->toBeGreaterThan(0)
        ->and(DB::table('stripe_payments')->count())->toBeGreaterThan(0)
        ->and(DB::table('stripe_disputes')->count())->toBeGreaterThan(0);
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

    livewire(ActiveDropsTableWidget::class)
        ->assertSee('Douwe Egberts Aroma Rood koffiebonen 1 kg');

    livewire(RecentNotificationsTableWidget::class)
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
