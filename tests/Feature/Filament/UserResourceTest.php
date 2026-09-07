<?php declare(strict_types=1);

use App\Billing\Plan;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

test('the users screen lists every account, not only subscribers', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $neverPaid = User::factory()->create();

    livewire(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$admin, $neverPaid]);
});

test('the users screen renders with no Stripe configured', function (): void {
    // SubscriberResource hides itself without Stripe. This one must not:
    // an owner has accounts to look at before they sell anything.
    config()->set('cashier.key', null);
    config()->set('cashier.secret', null);

    $this->actingAs(User::factory()->admin()->create());

    livewire(ListUsers::class)->assertOk();
});

test('a non-admin cannot reach the users screen', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(UserResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

test('an admin can comp an account forever', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $target = User::factory()->create();

    expect($target->plan())->toBe(Plan::Free);

    livewire(ListUsers::class)
        ->callAction(
            TestAction::make('comp')->table($target),
            ['duration' => 'forever', 'reason' => 'Beta feedback'],
        )
        ->assertHasNoActionErrors();

    $target = $target->fresh();

    expect($target?->plan())->toBe(Plan::Pro)
        ->and($target?->comped_reason)->toBe('Beta feedback')
        ->and($target?->comped_until?->year)->toBe(2099);
});

test('comping requires a reason', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $target = User::factory()->create();

    livewire(ListUsers::class)
        ->callAction(
            TestAction::make('comp')->table($target),
            ['duration' => 'month', 'reason' => ''],
        )
        ->assertHasActionErrors(['reason']);

    expect($target->fresh()?->comped_until)->toBeNull();
});

test('an admin can end a comp, and the account drops to free', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $target = User::factory()->create([
        'comped_until' => CarbonImmutable::now()->addYear(),
        'comped_reason' => 'Beta feedback',
    ]);

    expect($target->plan())->toBe(Plan::Pro);

    livewire(ListUsers::class)
        ->callAction(TestAction::make('endComp')->table($target))
        ->assertHasNoActionErrors();

    $target = $target->fresh();

    expect($target?->plan())->toBe(Plan::Free)
        ->and($target?->comped_until)->toBeNull()
        ->and($target?->comped_reason)->toBeNull();
});

test('the end-comp action is hidden for an account that is not comped', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $target = User::factory()->create();

    livewire(ListUsers::class)
        ->assertActionHidden(TestAction::make('endComp')->table($target));
});

test('a comped account with a live subscription is allowed, and both survive', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $target = User::factory()->create();
    $subscription = subscribeUser($target, 'active');

    livewire(ListUsers::class)
        ->callAction(
            TestAction::make('comp')->table($target),
            ['duration' => 'year', 'reason' => 'Refund in progress'],
        )
        ->assertHasNoActionErrors();

    expect($target->fresh()?->plan())->toBe(Plan::Pro)
        ->and($subscription->fresh()?->stripe_status)->toBe('active');
});

test('the products count does not add a query per row', function (): void {
    // Previously this asserted only assertOk(): deleting the eager load from
    // UsersTable left it green. Now the query count is the assertion.
    $this->actingAs(User::factory()->admin()->create());

    $users = User::factory()->count(5)->create();

    foreach ($users as $user) {
        Product::factory()->count(2)->create(['user_id' => $user->id]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    livewire(ListUsers::class)->assertOk()->assertCanSeeTableRecords($users);

    // Six accounts on the page. Without ->with('subscriptions')->withCount()
    // this climbs with the row count; with them it does not.
    expect($queries)->toBeLessThan(15);
});

test('a non-admin cannot invoke the comp action even if they reach the page', function (): void {
    // The panel gate is one layer; the action checks is_admin itself so it
    // stays safe if it is ever reused outside this panel.
    $target = User::factory()->create();

    $this->actingAs(User::factory()->create());

    livewire(ListUsers::class)
        ->assertActionHidden(TestAction::make('comp')->table($target));

    expect($target->fresh()?->comped_until)->toBeNull();
});
