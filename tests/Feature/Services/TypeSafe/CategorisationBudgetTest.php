<?php declare(strict_types=1);

use App\Models\User;
use App\Services\TypeSafe\CategorisationBudget;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config()->set('dipcatch.categories.daily_limit_per_user', 2);
    config()->set('dipcatch.categories.daily_limit', 3);
    RateLimiter::clear(CategorisationBudget::appKey());
});

it('allows an account its daily calls and then refuses', function (): void {
    $user = User::factory()->create();
    $budget = new CategorisationBudget();

    expect($budget->allows($user))->toBeTrue()
        ->and($budget->allows($user))->toBeTrue()
        ->and($budget->allows($user))->toBeFalse();
});

it('caps the whole app across accounts', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $budget = new CategorisationBudget();

    expect($budget->allows($a))->toBeTrue()
        ->and($budget->allows($a))->toBeTrue()
        ->and($budget->allows($b))->toBeTrue()
        ->and($budget->allows($b))->toBeFalse();
});

it('lifts a cap set to zero', function (): void {
    config()->set('dipcatch.categories.daily_limit_per_user', 0);
    config()->set('dipcatch.categories.daily_limit', 0);
    $user = User::factory()->create();
    $budget = new CategorisationBudget();

    foreach (range(1, 5) as $ignored) {
        expect($budget->allows($user))->toBeTrue();
    }
});
