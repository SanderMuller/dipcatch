<?php declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;

it('wants automatic categories on Pro with the switch on', function (): void {
    $user = User::factory()->create(['auto_categories' => true]);
    subscribeUser($user);

    expect($user->wantsAutoCategories())->toBeTrue();
});

it('does not want automatic categories on Pro with the switch off', function (): void {
    $user = User::factory()->create(['auto_categories' => false]);
    subscribeUser($user);

    expect($user->wantsAutoCategories())->toBeFalse();
});

it('does not want automatic categories on the free plan even with the switch on', function (): void {
    $user = User::factory()->create(['auto_categories' => true]);

    expect($user->wantsAutoCategories())->toBeFalse()
        ->and($user->auto_categories)->toBeTrue();
});

it('counts a comped account as Pro', function (): void {
    $user = User::factory()->create([
        'auto_categories' => true,
        'comped_until' => CarbonImmutable::now()->addMonth(),
    ]);

    expect($user->wantsAutoCategories())->toBeTrue();
});
