<?php declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Models\Product;

it('round-trips the category and its source through the enums', function (): void {
    $product = Product::factory()->categorised(ProductCategory::CoffeeTea, CategorySource::Auto)->create();

    $fresh = Product::query()->findOrFail($product->id);

    expect($fresh->category)->toBe(ProductCategory::CoffeeTea)
        ->and($fresh->category_set_by)->toBe(CategorySource::Auto);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'category' => 'food.coffee_tea', 'category_set_by' => 'auto']);
});

it('creates a product with no category by default', function (): void {
    $product = Product::factory()->create();

    expect($product->fresh()?->category)->toBeNull()
        ->and($product->fresh()?->category_set_by)->toBeNull();
});
