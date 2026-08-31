<?php declare(strict_types=1);

use App\Health\CheckjebonFreshnessCheck;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Models\Shop;
use Spatie\Health\Facades\Health;

function freshnessShop(): Shop
{
    $product = Product::factory()->create(['currency' => 'EUR', 'active' => true]);

    return Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi257/roomkaas',
        'adapter_key' => 'checkjebon',
        'active' => true,
    ]);
}

function freshnessRow(DateTimeInterface $refreshedAt): void
{
    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => 'wi257',
        'name' => 'AH Kruiden roomkaas',
        'price' => '1.25',
        'size' => null,
        'refreshed_at' => $refreshedAt,
    ]);
}

test('the freshness check is registered', function (): void {
    $registered = collect(Health::registeredChecks())
        ->map(fn ($check): string => $check::class)
        ->all();

    expect($registered)->toContain(CheckjebonFreshnessCheck::class);
});

test('idle when no active shop uses the dataset', function (): void {
    $result = new CheckjebonFreshnessCheck()->run();

    expect($result->status->value)->toBe('ok')
        ->and($result->shortSummary)->toBe('idle');
});

test('fails when the dataset is empty but shops depend on it', function (): void {
    freshnessShop();

    $result = new CheckjebonFreshnessCheck()->run();

    expect($result->status->value)->toBe('failed')
        ->and($result->shortSummary)->toBe('empty');
});

test('ok when the dataset is fresh — a single skipped upstream day stays quiet', function (): void {
    freshnessShop();
    freshnessRow(now()->subHours(30)); // ~1 skipped day

    $result = new CheckjebonFreshnessCheck()->run();

    expect($result->status->value)->toBe('ok');
});

test('warns past 48 hours', function (): void {
    freshnessShop();
    freshnessRow(now()->subHours(49));

    $result = new CheckjebonFreshnessCheck()->run();

    expect($result->status->value)->toBe('warning');
});

test('fails past 96 hours', function (): void {
    freshnessShop();
    freshnessRow(now()->subHours(97));

    $result = new CheckjebonFreshnessCheck()->run();

    expect($result->status->value)->toBe('failed');
});
