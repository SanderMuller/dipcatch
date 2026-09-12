<?php declare(strict_types=1);

use App\Billing\StripeCustomers;
use App\Livewire\Settings\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('profile page is displayed', function (): void {
    $this->actingAs($user = User::factory()->create());

    $this->get('/settings/profile')->assertOk();
});

test('profile information can be updated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User')
        ->and($user->email)->toEqual('test@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull()
        ->and(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

test('deleting your own account removes the Stripe customer and the rows no foreign key covers', function (): void {
    $user = User::factory()->create(['stripe_id' => 'cus_self']);

    DB::table('sessions')->insert([
        'id' => 'session-self',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    $deleted = null;

    app()->instance(StripeCustomers::class, new class ($deleted) extends StripeCustomers {
        public function __construct(public ?string &$deleted) {}

        public function delete(string $stripeId): void
        {
            $this->deleted = $stripeId;
        }
    });

    $this->actingAs($user);

    Livewire::test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($deleted)->toBe('cus_self')
        ->and($user->fresh())->toBeNull()
        ->and(DB::table('sessions')->where('id', 'session-self')->exists())->toBeFalse();
});

test('a Stripe failure leaves your account in place and signed in', function (): void {
    $user = User::factory()->create(['stripe_id' => 'cus_self']);

    app()->instance(StripeCustomers::class, new class extends StripeCustomers {
        public function delete(string $stripeId): void
        {
            throw new RuntimeException('Stripe is unreachable');
        }
    });

    $this->actingAs($user);

    Livewire::test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull()
        ->and(auth()->check())->toBeTrue();
});
