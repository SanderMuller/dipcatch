<?php declare(strict_types=1);

use App\Filament\App\Widgets\SavingsByMonthChartWidget;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Livewire\livewire;

function fireDropEvent(User $user, Product $product, string $currency, string $abs, CarbonImmutable $firedAt): PriceDropEvent
{
    $check = PriceCheck::factory()->for($product)->create();

    return PriceDropEvent::factory()->state([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'price_check_id' => $check->id,
        'currency' => $currency,
        'drop_abs' => $abs,
        'fired_at' => $firedAt,
    ])->create();
}

test('chart returns 12 month labels covering the trailing year', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = livewire(SavingsByMonthChartWidget::class);
    /** @var SavingsByMonthChartWidget $widget */
    $widget = $component->instance();
    $data = $widget->computeData();

    expect($data['labels'])->toHaveCount(12)
        ->and($data['labels'][11])->toBe(CarbonImmutable::now()->format('M Y'));
});

test('aggregates per-currency drops into the correct month buckets', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();

    $thisMonth = CarbonImmutable::now()->startOfMonth()->addDays(2);
    $lastMonth = $thisMonth->subMonth();

    fireDropEvent($user, $product, 'EUR', '15.00', $thisMonth);
    fireDropEvent($user, $product, 'EUR', '5.00', $thisMonth);
    fireDropEvent($user, $product, 'USD', '7.50', $lastMonth);

    $this->actingAs($user);
    /** @var SavingsByMonthChartWidget $widget */
    $widget = livewire(SavingsByMonthChartWidget::class)->instance();
    $data = $widget->computeData();

    $datasets = [];
    foreach ($data['datasets'] as $set) {
        $datasets[$set['label']] = $set;
    }

    expect($datasets)->toHaveKey('EUR')
        ->and($datasets)->toHaveKey('USD')
        ->and($datasets['EUR']['data'][11])->toBe(20.0) // this-month sum
        ->and($datasets['USD']['data'][10])->toBe(7.5); // last-month sum
});

test('does not include other users events', function (): void {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $myProduct = Product::factory()->for($me)->create();
    $theirProduct = Product::factory()->for($other)->create();

    fireDropEvent($me, $myProduct, 'EUR', '10.00', CarbonImmutable::now());
    fireDropEvent($other, $theirProduct, 'EUR', '999.00', CarbonImmutable::now());

    $this->actingAs($me);
    /** @var SavingsByMonthChartWidget $widget */
    $widget = livewire(SavingsByMonthChartWidget::class)->instance();
    $data = $widget->computeData();

    $datasets = [];
    foreach ($data['datasets'] as $set) {
        $datasets[$set['label']] = $set;
    }
    expect($datasets['EUR']['data'][11])->toBe(10.0);
});

test('events older than 12 months are excluded', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();

    fireDropEvent($user, $product, 'EUR', '99.00', CarbonImmutable::now()->subMonths(13));

    $this->actingAs($user);
    /** @var SavingsByMonthChartWidget $widget */
    $widget = livewire(SavingsByMonthChartWidget::class)->instance();
    $data = $widget->computeData();

    expect($data['datasets'])->toBe([]);
});

test('empty state: no events → no datasets, but the 12-month label band still renders', function (): void {
    $this->actingAs(User::factory()->create());
    /** @var SavingsByMonthChartWidget $widget */
    $widget = livewire(SavingsByMonthChartWidget::class)->instance();
    $data = $widget->computeData();

    expect($data['datasets'])->toBe([])
        ->and($data['labels'])->toHaveCount(12);
});
