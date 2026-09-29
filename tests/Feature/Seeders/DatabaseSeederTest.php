<?php declare(strict_types=1);

use App\Models\CheckjebonPrice;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

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

it('can call the price-dataset refresh by the name the seeder uses', function (): void {
    // The seeder used to reach for `$this->callSilent()`, which resolves its
    // argument as a seeder class — so the Artisan signature arrived at the
    // container as a class name and `migrate:fresh --seed` died on
    // "Target class [dipcatch:refresh-checkjebon] does not exist". A guard
    // that skips this work in tests is what let it ship, so the command name
    // is asserted here rather than trusted.
    expect(Artisan::all())->toHaveKey('dipcatch:refresh-checkjebon');

    Http::fake([
        'https://raw.githubusercontent.com/*' => Http::response([[
            'n' => 'ah',
            'd' => [['n' => 'Testproduct', 'l' => 'wi1/testproduct', 'p' => 1.99, 's' => '300 g']],
        ]]),
    ]);

    expect(Artisan::call('dipcatch:refresh-checkjebon'))->toBe(0)
        ->and(CheckjebonPrice::query()->where('external_id', 'wi1')->exists())->toBeTrue();
});
