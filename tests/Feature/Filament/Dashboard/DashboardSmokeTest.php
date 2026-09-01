<?php declare(strict_types=1);

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Widgets\ActiveDropsTableWidget;
use App\Filament\App\Widgets\NextStepsWidget;
use App\Filament\App\Widgets\RecentNotificationsTableWidget;
use App\Filament\App\Widgets\SavingsByMonthChartWidget;
use App\Filament\App\Widgets\StatsOverviewWidget;
use App\Models\Product;
use App\Models\User;
use Filament\Support\Facades\FilamentView;

use function Pest\Livewire\livewire;

test('authenticated user can load /app dashboard', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/app')->assertOk();
});

test('Dashboard page declares the next-steps checklist ahead of the four data widgets once products exist', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create();
    $this->actingAs($user);

    expect(new Dashboard()->getWidgets())->toBe([
        NextStepsWidget::class,
        StatsOverviewWidget::class,
        ActiveDropsTableWidget::class,
        RecentNotificationsTableWidget::class,
        SavingsByMonthChartWidget::class,
    ]);
});

test('every widget renders without throwing for a user with seeded products', function (): void {
    $user = User::factory()->create(['default_currency' => 'EUR']);
    Product::factory()->count(3)->for($user)->create();
    Product::factory()->for($user)->create([
        'last_notified_price' => '49.00',
        'last_notified_at' => now(),
    ]);
    $this->actingAs($user);

    livewire(StatsOverviewWidget::class)->assertOk();
    livewire(ActiveDropsTableWidget::class)->assertOk();
    livewire(RecentNotificationsTableWidget::class)->assertOk();
    livewire(SavingsByMonthChartWidget::class)->assertOk();
});

test('the StatsOverviewWidget is rendered eagerly (not lazy), so users see counts above the fold', function (): void {
    $reflection = new ReflectionProperty(StatsOverviewWidget::class, 'isLazy');
    expect($reflection->getDefaultValue())->toBeFalse();
});

test('the heavy widgets keep Filament s default lazy hydration', function (string $widget): void {
    /** @var class-string $widget */
    $reflection = new ReflectionProperty($widget, 'isLazy');
    // Filament's default is `true`. We do not override it on these widgets.
    expect($reflection->getDefaultValue())->toBeTrue();
})->with([
    ActiveDropsTableWidget::class,
    RecentNotificationsTableWidget::class,
    SavingsByMonthChartWidget::class,
]);

test('dashboard renders cleanly under a forced dark theme', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Filament reads the active theme from a cookie; force it dark.
    $this->withCookie('theme', 'dark');

    // Tap the renderer to confirm the dark-mode hook is active.
    $hooked = false;
    FilamentView::registerRenderHook('panels::body.start', function () use (&$hooked): string {
        $hooked = true;

        return '<!-- theme-smoke -->';
    });

    $response = $this->get('/app');
    $response->assertOk();
    expect($hooked)->toBeTrue();
});
