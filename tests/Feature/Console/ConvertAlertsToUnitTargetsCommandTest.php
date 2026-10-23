<?php declare(strict_types=1);

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Two shops selling by weight: 200 g at €1.69 and 370 g at €1.99, so the
 * best value is the 370 g bag at €5.3784 a kilo.
 *
 * @param  array<model-property<Product>, mixed>  $attributes
 */
function productToConvert(array $attributes = []): Product
{
    $user = User::factory()->create(['notify_via_filament' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', ...$attributes]);

    Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1', 'currency' => 'EUR', 'current_price' => '1.69',
        'pack_quantity' => '200.00', 'pack_unit' => 'g',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://lidl.nl/p/1', 'currency' => 'EUR', 'current_price' => '1.99',
        'pack_quantity' => '370.00', 'pack_unit' => 'g', 'created_at' => now()->addMinute(),
    ]);

    return $product->refresh();
}

/**
 * The same, with no size anywhere: nobody can win per kilo.
 *
 * @param  array<model-property<Product>, mixed>  $attributes
 */
function sizelessProductToConvert(array $attributes = [], ?string $firstPrice = '24.00'): Product
{
    $product = Product::factory()->create(['currency' => 'EUR', ...$attributes]);

    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/12', 'currency' => 'EUR', 'current_price' => $firstPrice]);
    Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/p/1', 'currency' => 'EUR', 'current_price' => '2.99', 'created_at' => now()->addMinute(),
    ]);

    return $product->refresh();
}

function convertAlerts(bool $dryRun = false): void
{
    test()->artisan('dipcatch:convert-alerts-to-unit-targets', $dryRun ? ['--dry-run' => true] : [])->assertSuccessful();
}

beforeEach(function (): void {
    Notification::fake();
});

it('keeps the highest target the old settings give, per kilo on the best value', function (): void {
    // €1.80 for 370 g is €4.8648/kg; 10% under €5.3784 is €4.8405; €0.20 off €1.99 is €4.8378.
    $product = productToConvert(['target_price' => '1.80', 'drop_threshold_pct' => '10.00', 'drop_threshold_abs' => '0.20']);

    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBe('4.8648')
        ->and($product->unit_price_target_unit)->toBe('g')
        ->and($product->unit_price_target_shop_id)->toBeNull()
        ->and($product->unit_price_notified)->toBeNull()
        // Kept until the columns are dropped, as a way back.
        ->and((string) $product->target_price)->toBe('1.80');
});

it('turns a drop percentage into a target under today\'s best', function (): void {
    $product = productToConvert(['drop_threshold_pct' => '10.00']);

    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBe('4.8405')
        ->and($product->unit_price_target_unit)->toBe('g');
});

it('marks a converted target that is met today as heard, and sends nothing', function (): void {
    $product = productToConvert(['target_price' => '2.50']);

    convertAlerts();
    app(DetectUnitPriceTarget::class)($product->refresh());

    expect($product->unit_price_target)->toBe('6.7567')
        ->and($product->unit_price_notified)->toBe('5.3784')
        ->and($product->unit_price_notified_unit)->toBe('g');
    Notification::assertNothingSent();
});

it('skips a product that already has a target per unit', function (): void {
    $product = productToConvert(['unit_price_target' => '5.00', 'unit_price_target_unit' => 'g', 'target_price' => '1.80', 'drop_threshold_pct' => '10.00']);

    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBe('5.0000')
        ->and($product->unit_price_target_unit)->toBe('g');
});

it('skips an amount that takes the whole pack price, and keeps the default drop', function (): void {
    $product = productToConvert(['drop_threshold_abs' => '5.00']);

    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBeNull();
});

it('pins a price for any pack to the first shop when nobody wins per unit', function (): void {
    $product = sizelessProductToConvert(['target_price' => '22.80']);
    $first = $product->shops()->where('url', 'https://ah.nl/p/12')->sole();

    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBe('22.8000')
        ->and($product->unit_price_target_unit)->toBe('pack')
        ->and($product->unit_price_target_shop_id)->toBe($first->id)
        // The single bar at €2.99 elsewhere does not meet it.
        ->and($product->isAtTarget())->toBeFalse();
});

it('takes a drop off the first shop\'s price when nobody wins per unit', function (): void {
    $product = sizelessProductToConvert(['drop_threshold_pct' => '10.00', 'drop_threshold_abs' => '3.00']);

    convertAlerts();

    // 10% off €24.00 is €21.60; €3.00 off is €21.00.
    expect($product->refresh()->unit_price_target)->toBe('21.6000')
        ->and($product->unit_price_target_unit)->toBe('pack');
});

it('keeps the default drop when the first shop has no price and only drops are set', function (): void {
    $product = sizelessProductToConvert(['drop_threshold_pct' => '10.00'], firstPrice: null);

    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBeNull();
});

it('converts a paused product without alerting', function (): void {
    $product = productToConvert(['active' => false, 'target_price' => '2.50']);

    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBe('6.7567');
    Notification::assertNothingSent();
});

it('stamps a target with no unit on a product that never compared per unit as a price per pack', function (): void {
    $product = sizelessProductToConvert(['unit_price_target' => '22.80']);
    $first = $product->shops()->where('url', 'https://ah.nl/p/12')->sole();

    convertAlerts();

    expect($product->refresh()->unit_price_target_unit)->toBe('pack')
        ->and($product->unit_price_target_shop_id)->toBe($first->id)
        ->and($product->unit_price_target)->toBe('22.8000');
});

it('changes nothing on a second run', function (): void {
    $product = productToConvert(['drop_threshold_pct' => '10.00']);

    convertAlerts();
    $product->refresh()->forceFill(['unit_price_notified' => '4.5000', 'unit_price_notified_unit' => 'g'])->save();
    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBe('4.8405')
        ->and($product->unit_price_notified)->toBe('4.5000');
});

it('leaves an alert the owner saved while the command ran', function (): void {
    $product = productToConvert(['drop_threshold_pct' => '10.00']);
    $edited = false;

    // The owner saves a target after the command picked the product, before its row lock.
    Product::retrieved(function (Product $retrieved) use ($product, &$edited): void {
        if ($edited || $retrieved->id !== $product->id) {
            return;
        }

        $edited = true;
        Product::query()->whereKey($product->id)->update(['unit_price_target' => '3.00', 'unit_price_target_unit' => 'g']);
    });

    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBe('3.0000');
});

it('changes nothing on a dry run, and counts drops that equal a default', function (): void {
    $product = productToConvert(['drop_threshold_pct' => '15.00', 'drop_threshold_abs' => '3.00']);

    $this->artisan('dipcatch:convert-alerts-to-unit-targets', ['--dry-run' => true])
        ->expectsOutputToContain('converted to a price per unit: 1')
        ->expectsOutputToContain('Stored drops that equal a default: 1')
        ->assertSuccessful();

    expect($product->refresh()->unit_price_target)->toBeNull();
});

it('stamps a target with no unit without dropping the latch it has', function (): void {
    $product = sizelessProductToConvert(['unit_price_target' => '22.80', 'unit_price_notified' => '21.00', 'unit_price_notified_at' => now()]);

    convertAlerts();

    expect($product->refresh()->unit_price_target_unit)->toBe('pack')
        ->and($product->unit_price_notified)->toBe('21.0000')
        ->and($product->unit_price_notified_unit)->toBe('pack');
});

it('marks a converted price per pack that is met today as heard, and sends nothing', function (): void {
    $product = sizelessProductToConvert(['target_price' => '25.00']);

    convertAlerts();
    app(DetectUnitPriceTarget::class)($product->refresh());

    expect($product->unit_price_target_unit)->toBe('pack')
        ->and($product->unit_price_notified)->toBe('24.0000')
        ->and($product->unit_price_notified_unit)->toBe('pack');
    Notification::assertNothingSent();
});

it('keeps a drop percentage when it gives a higher target than the price for any pack', function (): void {
    // €1.50 for 370 g is €4.0540/kg; 5% off €1.99 for 370 g is €5.1094/kg.
    $product = productToConvert(['target_price' => '1.50', 'drop_threshold_pct' => '5.00']);

    convertAlerts();

    expect($product->refresh()->unit_price_target)->toBe('5.1094');
});

it('stamps nothing on a dry run of the backfill', function (): void {
    $product = sizelessProductToConvert(['unit_price_target' => '22.80']);

    $this->artisan('dipcatch:convert-alerts-to-unit-targets', ['--dry-run' => true])->assertSuccessful();

    expect($product->refresh()->unit_price_target_unit)->toBeNull()
        ->and($product->unit_price_target_shop_id)->toBeNull();
});

it('marks a converted target on a paused product as heard when it is met', function (): void {
    $product = productToConvert(['active' => false, 'target_price' => '2.50']);

    convertAlerts();

    expect($product->refresh()->unit_price_notified)->toBe('5.3784');
});

it('pins to the next shop when the first one is no longer tracked', function (): void {
    $product = sizelessProductToConvert(['target_price' => '22.80']);
    $product->shops()->where('url', 'https://ah.nl/p/12')->update(['active' => false]);
    $second = $product->shops()->where('url', 'https://jumbo.com/p/1')->sole();

    convertAlerts();

    expect($product->refresh()->unit_price_target_shop_id)->toBe($second->id);
});
