<?php declare(strict_types=1);

/*
 * Product edit is NOT rebuilt.
 *
 * The Flux migration (specs/flux-user-app-migration.md) rebuilt product create,
 * view, delete and the per-shop operations, but `/app/products/{product}/edit`
 * is still a placeholder route. The shop image picker lived on that form.
 *
 * The original cases are in git history at the commit that deleted
 * tests/Feature/Shops/ShopImagePickerTest.php — they referenced
 * App\Filament\App\Resources\Products\{Pages\EditProduct, Schemas\ProductForm},
 * which no longer exist, so the file could not be kept as a skipped stub
 * without breaking static analysis. This test is the marker that the gap is
 * known rather than forgotten.
 */

it('has no product edit page yet', function (): void {
    $route = app('router')->getRoutes()->getByName('app.products.edit');

    expect($route)->not->toBeNull();
})->todo('Rebuild the product edit form, including the shop image picker.');
