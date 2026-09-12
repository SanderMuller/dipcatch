<?php declare(strict_types=1);

use App\Billing\Plan;
use App\Enums\ScrapeStatus;
use App\Enums\ShopHealth;
use App\Filament\Admin\Widgets\OperationsOverviewWidget;
use App\Filament\Admin\Widgets\ShopsNeedingAttentionWidget;
use App\Filament\Admin\Widgets\SubscriptionOverviewWidget;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;

use function Pest\Livewire\livewire;

function adminUser(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('shows the operations widget to an admin even with no billing configured', function (): void {
    $admin = adminUser();
    $product = Product::factory()->create(['user_id' => $admin->id, 'active' => true]);
    Shop::factory()->for($product)->create(['active' => true, 'health' => ShopHealth::Ok]);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    // The revenue widget hides itself until Stripe is configured; this one
    // must not, or the dashboard is empty on a working installation.
    expect(OperationsOverviewWidget::canView())->toBeTrue();

    livewire(OperationsOverviewWidget::class)
        ->assertSee('Accounts')
        ->assertSee('Active products')
        ->assertSee('Scrapes healthy');
});

it('reports the share of offers the scraper can still read', function (): void {
    $admin = adminUser();
    $product = Product::factory()->create(['user_id' => $admin->id]);
    Shop::factory()->count(3)->for($product)->create(['active' => true, 'health' => ShopHealth::Ok]);
    Shop::factory()->for($product)->create(['active' => true, 'health' => ShopHealth::Dead]);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    // 3 of 4 readable.
    livewire(OperationsOverviewWidget::class)
        ->assertSee('75%')
        ->assertSee('0 failing, 1 dead');
});

it('lists only the active offers the scraper cannot read', function (): void {
    $admin = adminUser();
    $product = Product::factory()->create(['user_id' => $admin->id]);

    $broken = Shop::factory()->for($product)->create([
        'url' => 'https://broken.test/p/1',
        'active' => true,
        'health' => ShopHealth::Dead,
        'last_status' => ScrapeStatus::HttpError,
        'consecutive_failures' => 9,
    ]);
    $healthy = Shop::factory()->for($product)->create([
        'url' => 'https://healthy.test/p/1',
        'active' => true,
        'health' => ShopHealth::Ok,
    ]);
    // An offer the owner paused is not something to fix.
    $paused = Shop::factory()->for($product)->create([
        'url' => 'https://paused.test/p/1',
        'active' => false,
        'health' => ShopHealth::Dead,
    ]);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    livewire(ShopsNeedingAttentionWidget::class)
        ->assertCanSeeTableRecords([$broken])
        ->assertCanNotSeeTableRecords([$healthy, $paused]);
});

it('separates the 24-hour alert count from the 7-day one', function (): void {
    $admin = adminUser();
    $product = Product::factory()->create(['user_id' => $admin->id]);

    $fire = function (string $ago) use ($product, $admin): void {
        PriceDropEvent::factory()->create([
            'product_id' => $product->id,
            'user_id' => $admin->id,
            'fired_at' => now()->sub($ago),
        ]);
    };

    $fire('2 hours');   // inside both windows
    $fire('3 days');    // inside the week only
    $fire('10 days');   // outside both

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    // Asserting the labels alone would pass with the date filters removed,
    // so assert the two counts, and pick ages that tell the windows apart.
    // Read the stat itself rather than matching rendered HTML: an earlier
    // version of this test asserted on markup indentation and broke when the
    // template's whitespace shifted, which proved nothing either way.
    $widget = new OperationsOverviewWidget();
    $stats = new ReflectionMethod(OperationsOverviewWidget::class, 'getStats')->invoke($widget);
    $alerts = array_find(is_array($stats) ? $stats : [], fn ($stat): bool => $stat instanceof Stat && $stat->getLabel() === 'Drops alerted (24h)');

    expect($alerts)->toBeInstanceOf(Stat::class);
    assert($alerts instanceof Stat);

    $value = $alerts->getValue();
    $description = $alerts->getDescription();

    expect(is_scalar($value) ? (string) $value : '')->toBe('1')
        ->and(is_string($description) ? $description : '')->toBe('2 in the last 7 days');
});

it('leaves a paused product out of the operational numbers', function (): void {
    $admin = adminUser();
    $watched = Product::factory()->create(['user_id' => $admin->id, 'active' => true]);
    Shop::factory()->for($watched)->create(['active' => true, 'health' => ShopHealth::Ok]);

    // Pausing a product stops the scheduler rechecking its offers, so a dead
    // offer underneath one is not work anybody is meant to do.
    $paused = Product::factory()->create(['user_id' => $admin->id, 'active' => false]);
    $ignored = Shop::factory()->for($paused)->create(['active' => true, 'health' => ShopHealth::Dead]);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    livewire(OperationsOverviewWidget::class)
        ->assertSee('1 offers watched')
        // Without the parent filter the dead offer makes this 50%.
        ->assertSee('100%');

    livewire(ShopsNeedingAttentionWidget::class)
        ->assertCanNotSeeTableRecords([$ignored]);
});

it('offers an admin panel shortcut in the app user menu only to admins', function (): void {
    $admin = adminUser();
    $this->actingAs($admin);

    $this->get('/app')
        ->assertOk()
        ->assertSee('Admin panel');

    $plain = User::factory()->create(['is_admin' => false]);
    $this->actingAs($plain);

    $this->get('/app')
        ->assertOk()
        ->assertDontSee('Admin panel');
});

it('still refuses the admin panel to a non-admin', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    // The menu entry is a shortcut, never the thing that grants access.
    $this->get('/admin')->assertForbidden();
});

it('sizes the admin brand logo without relying on a compiled theme', function (): void {
    $this->actingAs(adminUser());

    // The admin panel compiles no Vite theme, so Tailwind utilities written in
    // a Blade partial do not exist in its CSS. The 512px logo then rendered at
    // full size and covered the sidebar. Inline styles are what fixed it.
    $this->get('/admin')
        ->assertOk()
        ->assertSee('height:2rem', escape: false)
        ->assertSee('images/dipcatch-logo.png', escape: false);
});

it('shows subscription entitlement even with no Stripe configured', function (): void {
    $admin = adminUser();

    // Three different doors into Pro, none of which needs Stripe wired up.
    $comped = User::factory()->create(['comped_until' => now()->addYear()]);
    $granted = User::factory()->create(['trial_ends_at' => now()->addDays(14)]);
    User::factory()->create(); // free

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    // The revenue widget hides itself without Stripe; this one must not, or
    // an owner running comps sees no subscribers at all.
    expect(SubscriptionOverviewWidget::canView())->toBeTrue();

    livewire(SubscriptionOverviewWidget::class)
        ->assertSee('Pro accounts')
        // admin + the free user are not Pro; comped and granted are.
        ->assertSee('0 paying · 1 on trial · 1 comped')
        ->assertSee('Comped');

    expect($comped->fresh()?->isPro())->toBeTrue()
        ->and($granted->fresh()?->isPro())->toBeTrue();
});

it('counts a comp with no end date separately', function (): void {
    $this->actingAs(adminUser());
    Filament::setCurrentPanel('admin');

    User::factory()->create(['comped_until' => Plan::COMPED_FOREVER]);

    livewire(SubscriptionOverviewWidget::class)
        ->assertSee('1 of them with no end date');
});

it('does not count a blocked account as entitled', function (): void {
    $this->actingAs(adminUser());
    Filament::setCurrentPanel('admin');

    // A lost chargeback withdraws Pro but keeps the customer.
    User::factory()->create([
        'comped_until' => now()->addYear(),
        'billing_blocked_at' => now(),
    ]);

    livewire(SubscriptionOverviewWidget::class)
        ->assertSee('0 paying · 0 on trial · 0 comped')
        ->assertSee('Pro withdrawn after a lost dispute');
});
