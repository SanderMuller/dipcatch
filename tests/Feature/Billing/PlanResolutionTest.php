<?php declare(strict_types=1);

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\ProUsers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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

it('keeps pro through the dunning retries while past due', function (): void {
    // A declined card is usually an expired card. Stripe keeps retrying, the
    // app asks the customer to fix it, and Pro survives until Stripe gives up.
    $user = User::factory()->create();
    subscribeUser($user, 'past_due');

    expect($user->plan())->toBe(Plan::Pro)
        ->and($user->isPastDue())->toBeTrue();
});

it('ends pro once Stripe gives up on the retries', function (): void {
    $user = User::factory()->create();
    subscribeUser($user, 'unpaid');

    expect($user->plan())->toBe(Plan::Free);
});

it('blocks pro when a chargeback was lost, even on an active subscription', function (): void {
    $user = User::factory()->create(['billing_blocked_at' => now()]);
    subscribeUser($user, 'active');

    expect($user->plan())->toBe(Plan::Free);
});

it('ends pro for an unpaid subscription even inside a future grace period', function (): void {
    // `valid()` alone would say yes here, while the scheduler's SQL says no.
    // One account cannot have two answers.
    $user = User::factory()->create();
    subscribeUser($user, 'unpaid', endsAt: CarbonImmutable::now()->addDays(10));

    expect($user->plan())->toBe(Plan::Free);
});

it('grants pro on a hand-granted trial read back from the database', function (): void {
    User::factory()->create([
        'email' => 'granted@example.test',
        'trial_ends_at' => CarbonImmutable::now()->addDays(30),
    ]);

    // Fresh from the database, not the in-memory model the factory returned:
    // without a datetime cast `trial_ends_at` arrives as a string and
    // Cashier's `onTrial()` fatals on it.
    $user = User::query()->where('email', 'granted@example.test')->firstOrFail();

    expect($user->trial_ends_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($user->plan())->toBe(Plan::Pro);
});

it('reads the free limits from config', function (): void {
    $entitlements = Entitlements::of(Plan::Free);

    expect($entitlements->maxProducts())->toBe(20)
        ->and($entitlements->maxShopsPerProduct())->toBe(4)
        ->and($entitlements->recheckIntervalHours())->toBe(24)
        ->and($entitlements->allowsUnitPriceAlerts())->toBeFalse();
});

it('gives pro unlimited products and shops', function (): void {
    $entitlements = Entitlements::of(Plan::Pro);

    expect($entitlements->maxProducts())->toBeNull()
        ->and($entitlements->maxShopsPerProduct())->toBeNull()
        ->and($entitlements->recheckIntervalHours())->toBe(6)
        ->and($entitlements->allowsUnitPriceAlerts())->toBeTrue();
});

/** The scheduler's reader, asked the same way `proAnswers()` asks it. */
function inProUsers(User $user): bool
{
    return DB::table('users')
        ->whereIn('id', ProUsers::ids())
        ->where('id', $user->getKey())
        ->exists();
}

/**
 * The two states where `plan()` and the scheduler's `ProUsers::ids()` used to
 * disagree. `valid()` is `active() || onTrial() || onGracePeriod()`, and the
 * subscription's own `trial_ends_at` reaches `onTrial()` independently of
 * whether the subscription has ended or was ever paid for. `ProUsers` selects
 * on Cashier's `active()` scope, which asks neither question — so one account
 * was Pro on the billing page and Free to the scheduler at the same instant.
 *
 * Asserted against both readers rather than the `proAnswers()` harness in
 * CompedAccountsTest: that harness also asserts `SubscribersTable::status()`,
 * which derives its own label and answers "Trial" for both of these. That is
 * F32's divergence, not this one. These cases join the harness when F32 lands.
 */
it('ends pro on an expired subscription whose own trial is still running', function (): void {
    $user = User::factory()->create();
    subscribeUser(
        $user,
        'canceled',
        endsAt: CarbonImmutable::now()->subDay(),
        trialEndsAt: CarbonImmutable::now()->addDays(10),
    );

    expect($user->plan())->toBe(Plan::Free)
        ->and(inProUsers($user))->toBeFalse();
});

it('ends pro on an incomplete subscription whose own trial is still running', function (): void {
    // Stripe never collected the first payment. A trial window on the same
    // row does not make it an entitlement.
    $user = User::factory()->create();
    subscribeUser($user, 'incomplete', trialEndsAt: CarbonImmutable::now()->addDays(10));

    expect($user->plan())->toBe(Plan::Free)
        ->and(inProUsers($user))->toBeFalse();
});
