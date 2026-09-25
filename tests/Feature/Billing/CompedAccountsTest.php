<?php declare(strict_types=1);

use App\Billing\Plan;
use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Billing\ProUsers;
use App\Filament\Admin\Resources\Subscribers\Tables\SubscribersTable;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The three places that decide whether an account is Pro. `ProUsers` warns in
 * its own docblock that if they disagree, "the scheduler and the admin count
 * stop matching what the customer is told", so every case below asserts all
 * three at once rather than trusting one.
 *
 * @return array{plan: bool, sql: bool, label: string, comped: bool}
 */
function proAnswers(User $user): array
{
    $inProUsers = DB::table('users')
        ->whereIn('id', ProUsers::ids())
        ->where('id', $user->getKey())
        ->exists();

    $fresh = $user->fresh() ?? $user;

    return [
        'plan' => $fresh->plan() === Plan::Pro,
        'sql' => $inProUsers,
        'label' => SubscribersTable::status($fresh),
        'comped' => $fresh->isComped(),
    ];
}

it('grants pro for a comp that has not expired', function (): void {
    $user = User::factory()->create(['comped_until' => CarbonImmutable::now()->addMonth()]);

    expect(proAnswers($user))->toBe(['plan' => true, 'sql' => true, 'label' => 'Comped', 'comped' => true]);
});

it('grants pro for a comp with no end date', function (): void {
    $user = User::factory()->create(['comped_until' => Plan::COMPED_FOREVER]);

    expect(proAnswers($user))->toBe(['plan' => true, 'sql' => true, 'label' => 'Comped', 'comped' => true]);
});

it('stops granting pro once a comp has expired', function (): void {
    $user = User::factory()->create(['comped_until' => CarbonImmutable::now()->subDay()]);

    expect(proAnswers($user))->toBe(['plan' => false, 'sql' => false, 'label' => 'Free', 'comped' => false]);
});

it('keeps a blocked account on free even while it is comped', function (): void {
    // Blocked beats comped: a lost chargeback is not undone by a past gift.
    $user = User::factory()->create([
        'billing_blocked_at' => CarbonImmutable::now(),
        'comped_until' => Plan::COMPED_FOREVER,
    ]);

    expect(proAnswers($user))->toBe(['plan' => false, 'sql' => false, 'label' => 'Blocked', 'comped' => false]);
});

it('leaves a paying subscription untouched when the account is also comped', function (): void {
    $user = User::factory()->create();
    $subscription = subscribeUser($user, 'active');
    $user->forceFill(['comped_until' => Plan::COMPED_FOREVER])->save();

    expect($user->fresh()?->plan())->toBe(Plan::Pro)
        ->and($subscription->fresh()?->stripe_status)->toBe('active');
});

it('agrees across all three readers at the moment a comp expires', function (): void {
    $user = User::factory()->create(['comped_until' => CarbonImmutable::now()->addHour()]);

    expect(proAnswers($user))->toBe(['plan' => true, 'sql' => true, 'label' => 'Comped', 'comped' => true]);

    $this->travel(2)->hours();

    expect(proAnswers($user))->toBe(['plan' => false, 'sql' => false, 'label' => 'Free', 'comped' => false]);
});

it('deletes nothing when an account already over the free limit is comped', function (): void {
    $user = User::factory()->create();
    $limits = app(PlanLimits::class);

    $allowed = $limits->remainingProducts($user);
    expect($allowed)->not->toBeNull();

    Product::factory()->count((int) $allowed)->create(['user_id' => $user->id]);

    $user->forceFill(['comped_until' => Plan::COMPED_FOREVER])->save();

    expect($user->fresh()?->products()->count())->toBe((int) $allowed)
        ->and($limits->canAddProduct($user->fresh() ?? $user))->toBeTrue();
});

it('keeps everything but blocks new products when a comp ends', function (): void {
    // PlanLimits bites on creation only, so ending a comp never removes data.
    $user = User::factory()->create(['comped_until' => CarbonImmutable::now()->addDay()]);
    $limits = app(PlanLimits::class);

    $freeAllowance = (int) $limits->remainingProducts(User::factory()->create());
    Product::factory()->count($freeAllowance + 1)->create(['user_id' => $user->id]);

    $user->forceFill(['comped_until' => null])->save();
    $user = $user->fresh() ?? $user;

    expect($user->products()->count())->toBe($freeAllowance + 1)
        ->and($limits->canAddProduct($user))->toBeFalse()
        ->and(fn () => $limits->guardProduct($user))->toThrow(PlanLimitReached::class);
});

it('keeps the history stamp after a comp ends, because the stamp never retracts', function (): void {
    // PruneOldChecksCommand::stampKeptHistory() is write-once by design, so
    // any comp permanently protects the history of the products the account
    // already owned. Accepted, not prevented — asserting it here so nobody
    // discovers it as a surprise later.
    $user = User::factory()->create(['comped_until' => CarbonImmutable::now()->addDay()]);
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect($product->fresh()?->history_kept_from)->not->toBeNull();

    $user->forceFill(['comped_until' => null])->save();

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect($product->fresh()?->history_kept_from)->not->toBeNull()
        ->and($user->fresh()?->plan())->toBe(Plan::Free);
});

test('a blocked account that is also comped reads as blocked everywhere', function (): void {
    // Four screens re-derived "comped" without the blocked precedence, so this
    // account showed a Free badge and "Pro is on us" on the same
    // line of the billing page.
    $user = User::factory()->create([
        'billing_blocked_at' => CarbonImmutable::now(),
        'comped_until' => Plan::COMPED_FOREVER,
    ]);

    $this->actingAs($user);

    $content = (string) $this->get('/app/billing')->assertOk()->getContent();

    expect($content)->not->toContain('Pro is on us')
        ->and($user->isComped())->toBeFalse();
});

it('reads pro everywhere when a live row sits under a newer incomplete one', function (): void {
    // Cashier's `subscription()` returns the newest row of a type, so every
    // reader built on it ignored a live subscription underneath a stray
    // `incomplete`. `ProUsers` matched the live one all along, which is how
    // the three answers came apart. Reported 2026-09-22.
    $user = User::factory()->create();

    subscribeUser($user, 'active')
        ->forceFill(['created_at' => CarbonImmutable::now()->subMonths(6)])->save();
    subscribeUser($user, 'incomplete')
        ->forceFill(['created_at' => CarbonImmutable::now()->subMinutes(5)])->save();

    expect(proAnswers($user))->toBe(['plan' => true, 'sql' => true, 'label' => 'Pro', 'comped' => false]);
});

it('labels each account by the branch that decided its plan', function (Closure $setUp, array $answers): void {
    $user = User::factory()->create();
    $setUp($user);

    expect(proAnswers($user))->toBe($answers);
})->with([
    'account trial, no subscription' => [
        fn (User $user) => $user->forceFill(['trial_ends_at' => CarbonImmutable::now()->addDays(10)])->save(),
        ['plan' => true, 'sql' => true, 'label' => 'Trial', 'comped' => false],
    ],
    'canceled row whose own trial still runs' => [
        fn (User $user) => subscribeUser($user, 'canceled', endsAt: CarbonImmutable::now()->subDay(), trialEndsAt: CarbonImmutable::now()->addDays(10)),
        ['plan' => false, 'sql' => false, 'label' => 'Free', 'comped' => false],
    ],
    'live trialing row' => [
        fn (User $user) => subscribeUser($user, 'trialing', trialEndsAt: CarbonImmutable::now()->addDays(5)),
        ['plan' => true, 'sql' => true, 'label' => 'Trial', 'comped' => false],
    ],
    'live row cancelling at period end' => [
        fn (User $user) => subscribeUser($user, 'active', endsAt: CarbonImmutable::now()->addDays(5)),
        ['plan' => true, 'sql' => true, 'label' => 'Cancelling', 'comped' => false],
    ],
    'past due row in dunning' => [
        fn (User $user) => subscribeUser($user, 'past_due'),
        ['plan' => true, 'sql' => true, 'label' => 'Past due', 'comped' => false],
    ],
    'incomplete row inside its trial' => [
        fn (User $user) => subscribeUser($user, 'incomplete', trialEndsAt: CarbonImmutable::now()->addDays(10)),
        ['plan' => false, 'sql' => false, 'label' => 'Free', 'comped' => false],
    ],
]);
