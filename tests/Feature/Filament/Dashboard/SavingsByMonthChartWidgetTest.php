<?php declare(strict_types=1);

use App\Filament\App\Widgets\SavingsByMonthChartWidget;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;

use function Pest\Livewire\livewire;

function fireDropEvent(User $user, Product $product, string $currency, string $abs, CarbonImmutable $firedAt): PriceDropEvent
{
    $shop = Shop::factory()->for($product)->create();
    $check = PriceCheck::factory()->for($shop)->create();

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

    $byCurrency = [];
    foreach ($data['datasets'] as $set) {
        $byCurrency[$set['label']] = $set['data'];
    }

    expect($byCurrency)->toHaveKey('€ saved')
        ->and(array_sum($byCurrency['€ saved']))->toBe(20.0)
        ->and($byCurrency)->toHaveKey('$ saved')
        ->and(array_sum($byCurrency['$ saved']))->toBe(7.5);
});

test('respects user scoping — only the current user\'s drops are counted', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceProduct = Product::factory()->for($alice)->create();
    $bobProduct = Product::factory()->for($bob)->create();

    $month = CarbonImmutable::now()->startOfMonth()->addDays();

    fireDropEvent($alice, $aliceProduct, 'EUR', '10.00', $month);
    fireDropEvent($bob, $bobProduct, 'EUR', '99.00', $month);

    $this->actingAs($alice);
    /** @var SavingsByMonthChartWidget $widget */
    $widget = livewire(SavingsByMonthChartWidget::class)->instance();
    $data = $widget->computeData();

    $eurDataset = collect($data['datasets'])->firstWhere('label', '€ saved');
    assert(is_array($eurDataset));

    expect(array_sum($eurDataset['data']))->toBe(10.0);
});

test('chart options carry the currency-aware tooltip formatter', function (): void {
    $options = (new ReflectionMethod(SavingsByMonthChartWidget::class, 'getOptions'))->invoke(new SavingsByMonthChartWidget());

    expect($options)->toBeInstanceOf(RawJs::class);
    assert($options instanceof RawJs);

    expect($options->toHtml())
        ->toContain('Intl.NumberFormat')
        ->toContain('ctx.dataset.currency');
});
