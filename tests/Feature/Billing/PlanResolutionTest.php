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
 * The admin labels for these rows are asserted in CompedAccountsTest, through
 * the `proAnswers()` harness.
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

it('ends pro on an incomplete subscription inside a future grace period', function (): void {
    // The third state this predicate change makes stricter, and the one the
    // first commit message missed. `onGracePeriod()` is only inside
    // `active()` when the status is not separately excluded — and
    // `incomplete` is, because `Cashier::$deactivateIncomplete` is left at
    // its default. Stripe never took a first payment, so a future `ends_at`
    // does not make this an entitlement.
    $user = User::factory()->create();
    subscribeUser($user, 'incomplete', endsAt: CarbonImmutable::now()->addDays(10));

    expect($user->plan())->toBe(Plan::Free)
        ->and(inProUsers($user))->toBeFalse();
});

/**
 * Cashier's `subscription()` returns the newest row of a type, so everything
 * built on it read one row and ignored the rest. `ProUsers` matches any active
 * one. Two rows were therefore enough to make the app and the scheduler
 * disagree about the same account at the same instant.
 */
function withStrayIncompleteRow(User $user): void
{
    subscribeUser($user, 'active')
        ->forceFill(['created_at' => CarbonImmutable::now()->subMonths(6)])->save();

    // An abandoned checkout, or a card Stripe never collected on. Nothing
    // ages this row out, so the account was stuck on the wrong answer.
    subscribeUser($user, 'incomplete')
        ->forceFill(['created_at' => CarbonImmutable::now()->subMinutes(5)])->save();

    $user->refresh()->load('subscriptions');
}

it('keeps pro when a live subscription sits under a newer incomplete one', function (): void {
    $user = User::factory()->create();
    withStrayIncompleteRow($user);

    expect($user->plan())->toBe(Plan::Pro)
        ->and($user->isPro())->toBeTrue()
        // The reader the scheduler uses always said Pro here. This is the
        // agreement that was missing.
        ->and(inProUsers($user))->toBeTrue();
});

it('keeps the entitlements a paying customer is owed under a stray row', function (): void {
    // The failure a customer would feel: history window, unit-price alerts and
    // the recheck cadence all follow the plan.
    $user = User::factory()->create(['auto_categories' => true]);
    withStrayIncompleteRow($user);

    expect($user->entitlements()->allowsUnitPriceAlerts())->toBeTrue()
        ->and($user->wantsAutoCategories())->toBeTrue();
});

it('does not offer a second checkout to an account Stripe already bills', function (): void {
    // The guard read the same single row, so a stray `incomplete` let the
    // billing page sell a second subscription to a live subscriber.
    $user = User::factory()->create();
    withStrayIncompleteRow($user);

    expect($user->payingSubscription()?->valid())->toBeTrue();

    // Both doors: the upgrade entry point and the checkout itself.
    $this->actingAs($user)->get('/upgrade')->assertRedirect('/app/billing');
    $this->actingAs($user)->get('/billing/checkout')->assertRedirect('/app/billing');
});

it('still drops to free when every row is dead', function (): void {
    $user = User::factory()->create();

    subscribeUser($user, 'canceled', endsAt: CarbonImmutable::now()->subDay())
        ->forceFill(['created_at' => CarbonImmutable::now()->subMonths(6)])->save();
    subscribeUser($user, 'incomplete')
        ->forceFill(['created_at' => CarbonImmutable::now()->subMinutes(5)])->save();

    $user->refresh()->load('subscriptions');

    expect($user->plan())->toBe(Plan::Free)
        ->and(inProUsers($user))->toBeFalse();
});
