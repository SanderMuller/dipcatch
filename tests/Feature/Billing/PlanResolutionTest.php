<?php declare(strict_types=1);

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;

it('puts a user with no subscription on the free plan', function (): void {
    expect(User::factory()->create()->plan())->toBe(Plan::Free);
});

it('puts an active subscriber on pro', function (): void {
    $user = User::factory()->create();
    subscribeUser($user, 'active');

    expect($user->plan())->toBe(Plan::Pro)
        ->and($user->isPro())->toBeTrue();
});

it('treats a trialing subscription as pro', function (): void {
    $user = User::factory()->create();
    subscribeUser($user, 'trialing', trialEndsAt: CarbonImmutable::now()->addDays(14));

    expect($user->plan())->toBe(Plan::Pro);
});

it('keeps pro during the grace period after cancelling', function (): void {
    $user = User::factory()->create();
    subscribeUser($user, 'active', endsAt: CarbonImmutable::now()->addDays(5));

    expect($user->plan())->toBe(Plan::Pro);
});

it('drops to free once the cancelled period has ended', function (): void {
    $user = User::factory()->create();
    subscribeUser($user, 'canceled', endsAt: CarbonImmutable::now()->subDay());

    expect($user->plan())->toBe(Plan::Free);
});

it('drops to free while past due', function (): void {
    $user = User::factory()->create();
    subscribeUser($user, 'past_due');

    expect($user->plan())->toBe(Plan::Free)
        ->and($user->isPastDue())->toBeTrue();
});

it('blocks pro when a chargeback was lost, even on an active subscription', function (): void {
    $user = User::factory()->create(['billing_blocked_at' => now()]);
    subscribeUser($user, 'active');

    expect($user->plan())->toBe(Plan::Free);
});

it('reads the free limits from config', function (): void {
    $entitlements = Entitlements::of(Plan::Free);

    expect($entitlements->maxProducts())->toBe(20)
        ->and($entitlements->maxShopsPerProduct())->toBe(4)
        ->and($entitlements->recheckIntervalHours())->toBe(6)
        ->and($entitlements->allowsUnitPriceAlerts())->toBeFalse();
});

it('gives pro unlimited products and shops', function (): void {
    $entitlements = Entitlements::of(Plan::Pro);

    expect($entitlements->maxProducts())->toBeNull()
        ->and($entitlements->maxShopsPerProduct())->toBeNull()
        ->and($entitlements->recheckIntervalHours())->toBe(2)
        ->and($entitlements->allowsUnitPriceAlerts())->toBeTrue();
});
