<?php declare(strict_types=1);

use App\Models\CheckjebonPrice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

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
