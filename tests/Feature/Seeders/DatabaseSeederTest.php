<?php declare(strict_types=1);

use App\Models\Product;
use Database\Seeders\DatabaseSeeder;

it('leaves the sample Feliway product on the admin account', function (): void {
    config()->set('dipcatch.admin.email', 'owner@dipcatch.test');
    config()->set('dipcatch.admin.password', 'password');

    $this->seed(DatabaseSeeder::class);

    // ZooplusFeliwaySeeder attaches to the oldest user, and DemoSeeder
    // backdates its accounts — so the call order in DatabaseSeeder decides
    // whether the sample product is visible to whoever logs in as admin.
    $product = Product::query()->where('title', 'Feliway Classic Verdamper 3-pack')->sole();

    expect($product->user?->is_admin)->toBeTrue()
        ->and($product->user?->email)->toBe('owner@dipcatch.test');
});
