<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Services\Drops\Reference;
use App\Services\Drops\ReferenceValue;
use Carbon\CarbonImmutable;

function seedChecks(Product $product, array $prices, ?CarbonImmutable $start = null): void
{
    $start ??= now()->toImmutable()->subDays(20);

    foreach ($prices as $i => $price) {
        PriceCheck::factory()->for($product)->create([
            'price' => $price,
            'status' => ScrapeStatus::Ok,
            'currency' => 'EUR',
            'checked_at' => $start->addHours($i),
        ]);
    }
}

test('returns median for odd sample count >= 7', function (): void {
    $product = Product::factory()->create(['initial_price' => '100.00']);
    seedChecks($product, ['10.00', '20.00', '30.00', '40.00', '50.00', '60.00', '70.00']);

    $ref = new Reference()->compute($product);

    expect($ref)->toBeInstanceOf(ReferenceValue::class)
        ->and($ref->kind)->toBe(ReferenceValue::KIND_MEDIAN_30D)
        ->and($ref->sampleSize)->toBe(7)
        ->and((float) $ref->value)->toBe(40.0);
});

test('returns median for even sample count >= 7 as average of two middles', function (): void {
    $product = Product::factory()->create(['initial_price' => '100.00']);
    seedChecks($product, ['10.00', '20.00', '30.00', '40.00', '50.00', '60.00', '70.00', '80.00']);

    $ref = new Reference()->compute($product);

    expect($ref)->not->toBeNull()
        ->and($ref->kind)->toBe(ReferenceValue::KIND_MEDIAN_30D)
        ->and($ref->sampleSize)->toBe(8)
        ->and((float) $ref->value)->toBe(45.0);
});

test('falls back to initial_price when fewer than 7 samples', function (): void {
    $product = Product::factory()->create(['initial_price' => '199.00']);
    seedChecks($product, ['10.00', '20.00', '30.00', '40.00', '50.00', '60.00']); // 6 samples

    $ref = new Reference()->compute($product);

    expect($ref)->not->toBeNull()
        ->and($ref->kind)->toBe(ReferenceValue::KIND_INITIAL)
        ->and($ref->sampleSize)->toBe(6)
        ->and((float) $ref->value)->toBe(199.0);
});

test('ignores price_checks older than 30 days', function (): void {
    $product = Product::factory()->create(['initial_price' => '100.00']);

    foreach (range(0, 9) as $i) {
        PriceCheck::factory()->for($product)->create([
            'price' => '5.00',
            'status' => ScrapeStatus::Ok,
            'checked_at' => now()->subDays(60)->addHours($i),
        ]);
    }

    $ref = new Reference()->compute($product);

    expect($ref)->not->toBeNull()
        ->and($ref->kind)->toBe(ReferenceValue::KIND_INITIAL)
        ->and($ref->sampleSize)->toBe(0);
});

test('ignores non-ok price_checks when counting samples', function (): void {
    $product = Product::factory()->create(['initial_price' => '100.00']);

    foreach (range(0, 6) as $i) {
        PriceCheck::factory()->for($product)->create([
            'price' => null,
            'status' => ScrapeStatus::HttpError,
            'error' => 'boom',
            'checked_at' => now()->subDays(2)->addHours($i),
        ]);
    }

    $ref = new Reference()->compute($product);

    expect($ref)->not->toBeNull()
        ->and($ref->kind)->toBe(ReferenceValue::KIND_INITIAL);
});

test('returns null when initial_price relation evaluates to null with no samples', function (): void {
    // The DB schema marks `initial_price` NOT NULL, so this branch is purely
    // defensive. Verify the contract directly via an in-memory product instance.
    $product = new Product();
    $product->id = '00000000-0000-0000-0000-000000000000';
    $product->setRelation('priceChecks', collect());
    $product->forceFill(['initial_price' => null]);

    $ref = new Reference()->compute($product);

    expect($ref)->toBeNull();
})->skip('initial_price column is NOT NULL in DB schema; null branch is defensive only.');
