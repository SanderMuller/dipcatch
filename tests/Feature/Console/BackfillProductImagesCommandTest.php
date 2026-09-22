<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;

it('gives each product without an image the image of its first shop that has one', function (): void {
    $bare = Product::factory()->create(['image_url' => null]);
    Shop::factory()->for($bare)->create(['created_at' => now()->subDays(3), 'image_url' => null]);
    Shop::factory()->for($bare)->create(['created_at' => now()->subDays(2)])->forceFill(['image_url' => 'https://ah.nl/first.png'])->save();
    Shop::factory()->for($bare)->create(['created_at' => now()->subDay()])->forceFill(['image_url' => 'https://jumbo.com/later.png'])->save();

    $unsafe = Product::factory()->create(['image_url' => 'javascript:alert(1)']);
    Shop::factory()->for($unsafe)->create()->forceFill(['image_url' => 'https://ah.nl/safe.png'])->save();

    $own = Product::factory()->create(['image_url' => 'https://example.com/own.png']);
    Shop::factory()->for($own)->create()->forceFill(['image_url' => 'https://ah.nl/other.png'])->save();

    $noShopImage = Product::factory()->create(['image_url' => null]);
    Shop::factory()->for($noShopImage)->create(['image_url' => null]);

    $this->artisan('dipcatch:backfill-product-images')
        ->expectsOutputToContain('2 product(s) got an image.')
        ->assertSuccessful();

    expect($bare->fresh()?->image_url)->toBe('https://ah.nl/first.png')
        ->and($unsafe->fresh()?->image_url)->toBe('https://ah.nl/safe.png')
        ->and($own->fresh()?->image_url)->toBe('https://example.com/own.png')
        ->and($noShopImage->fresh()?->image_url)->toBeNull();
});

it('only counts on a dry run', function (): void {
    $bare = Product::factory()->create(['image_url' => null]);
    Shop::factory()->for($bare)->create()->forceFill(['image_url' => 'https://ah.nl/first.png'])->save();

    $this->artisan('dipcatch:backfill-product-images', ['--dry-run' => true])
        ->expectsOutputToContain('[dry run] 1 product(s) would get an image.')
        ->assertSuccessful();

    expect($bare->fresh()?->image_url)->toBeNull();
});
