<?php declare(strict_types=1);

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\App\Widgets\GettingStartedWidget;
use App\Filament\App\Widgets\NextStepsWidget;
use App\Filament\App\Widgets\SavingsByMonthChartWidget;
use App\Filament\App\Widgets\StatsOverviewWidget;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

use function Pest\Livewire\livewire;

test('a user without products sees only the getting-started widget', function (): void {
    $this->actingAs(User::factory()->create());

    expect(new Dashboard()->getWidgets())->toBe([GettingStartedWidget::class]);

    $this->get('/app')
        ->assertOk()
        ->assertSee('Track your first product')
        ->assertSee(ProductResource::getUrl('create'))
        ->assertSee('ah.nl')
        ->assertDontSee('Tracked products');
});

test('a user with a single-shop product sees the next-steps checklist above the data widgets', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->for($product)->create();
    $this->actingAs($user);

    $widgets = new Dashboard()->getWidgets();

    expect($widgets)->not->toContain(GettingStartedWidget::class)
        ->and($widgets[0])->toBe(NextStepsWidget::class)
        ->and(NextStepsWidget::canView())->toBeTrue()
        ->and($widgets)->toContain(StatsOverviewWidget::class);

    livewire(NextStepsWidget::class)
        ->assertSee('Add a second shop to compare')
        ->assertSee(ProductResource::getUrl('view', ['record' => $product]));
});

test('the next-steps checklist disappears once a product has two shops', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    Shop::factory()->count(2)->for($product)->create();
    $this->actingAs($user);

    expect(NextStepsWidget::canView())->toBeFalse();

    $this->get('/app')->assertOk()->assertDontSee('Next steps');
});

test('another user\'s products do not hide the getting-started widget', function (): void {
    $other = User::factory()->create();
    Product::factory()->for($other)->create();
    $this->actingAs(User::factory()->create());

    expect(GettingStartedWidget::canView())->toBeTrue();
});

test('the empty products list offers a track-a-product action', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(ProductResource::getUrl('index'))
        ->assertOk()
        ->assertSee('No products yet')
        ->assertSee('Track a product');
});

test('the savings chart stays hidden until a drop has fired for the user', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create();
    $this->actingAs($user);

    expect(SavingsByMonthChartWidget::canView())->toBeFalse();

    PriceDropEvent::factory()->for($user)->create();

    expect(SavingsByMonthChartWidget::canView())->toBeTrue();
});

test('a search with no matches shows a no-results state instead of the onboarding empty state', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Beemster kaas']);
    $this->actingAs($user);

    livewire(ListProducts::class)
        ->searchTable('zzz-no-such-product')
        ->assertSee('No matching products')
        ->assertDontSee('No products yet')
        ->assertDontSee('Track a product');
});
