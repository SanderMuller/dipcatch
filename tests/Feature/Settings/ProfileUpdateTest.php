<?php declare(strict_types=1);

use App\Billing\StripeCustomers;
use App\Livewire\Settings\Profile;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('profile information can be updated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('current_password', 'password')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User')
        ->and($user->email)->toEqual('test@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('a new address is refused without the current password', function (?string $password): void {
    $user = User::factory()->create(['email' => 'eigen@example.test']);

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('email', 'nieuw@example.test')
        ->set('current_password', $password ?? '')
        ->call('updateProfileInformation')
        ->assertHasErrors(['current_password'])
        ->assertSet('current_password', '');

    $user->refresh();

    expect($user->email)->toBe('eigen@example.test')
        ->and($user->email_verified_at)->not->toBeNull();
})->with([
    'no password' => [null],
    'wrong password' => ['wrong-password'],
]);

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

test('an address another account holds is refused in any case', function (): void {
    User::factory()->create(['email' => 'bezet@example.test']);

    $this->actingAs(User::factory()->create());

    Livewire::test(Profile::class)
        ->set('email', 'Bezet@Example.test')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email' => 'unique']);
});

test('retyping the own address in capitals keeps it verified', function (): void {
    $user = User::factory()->create(['email' => 'eigen@example.test']);

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('email', 'Eigen@Example.test')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->email)->toBe('eigen@example.test')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('an address the lower-case backfill skipped can still save a new name', function (): void {
    // Two rows differing only in case: the backfill left the second as it was.
    User::factory()->create(['email' => 'dubbel@example.test']);
    $legacy = User::factory()->create(['email' => 'dubbel-2@example.test']);
    DB::table('users')->where('id', $legacy->id)->update(['email' => 'Dubbel@Example.test']);

    $this->actingAs($legacy->fresh() ?? $legacy);

    Livewire::test(Profile::class)
        ->set('name', 'Nieuwe Naam')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($legacy->fresh()?->name)->toBe('Nieuwe Naam')
        ->and($legacy->fresh()?->email)->toBe('Dubbel@Example.test');
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

test('the verification link can be sent again only so often', function (): void {
    // Security audit lead 10: the account names the address, which can be
    // anyone's, so the button must not send mail without end.
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $component = Livewire::test(Profile::class);

    for ($attempt = 1; $attempt <= Profile::VERIFICATION_RESENDS_PER_HOUR + 1; $attempt++) {
        $component->call('resendVerificationNotification');
    }

    Notification::assertSentToTimes($user, VerifyEmail::class, Profile::VERIFICATION_RESENDS_PER_HOUR);
});
