<?php declare(strict_types=1);

use App\Actions\Fortify\PasswordGuesses;
use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
 * A stolen session reaches every form that asks for the account's own
 * password. Without a limit it can guess until it passes the step-up.
 */

/**
 * Sends wrong passwords to the confirm page, as many as the limit allows.
 */
function guessWrongOnConfirmPage(int $times): void
{
    for ($guess = 1; $guess <= $times; $guess++) {
        test()->post(route('password.confirm.store'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
    }
}

test('the confirm page refuses even the right password after too many wrong guesses', function (): void {
    $this->actingAs(User::factory()->create());

    guessWrongOnConfirmPage(PasswordGuesses::WRONG_GUESSES);

    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasErrors(['password' => PasswordGuesses::lockedMessage(PasswordGuesses::DECAY_SECONDS)]);

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('the confirm page takes the right password again once the lock runs out', function (): void {
    $this->actingAs(User::factory()->create());

    guessWrongOnConfirmPage(PasswordGuesses::WRONG_GUESSES);

    $this->travel(PasswordGuesses::DECAY_SECONDS + 1)->seconds();

    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('the right password resets the count of wrong guesses', function (): void {
    $this->actingAs(User::factory()->create());

    guessWrongOnConfirmPage(PasswordGuesses::WRONG_GUESSES - 1);

    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    guessWrongOnConfirmPage(PasswordGuesses::WRONG_GUESSES - 1);

    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasNoErrors();
});

test('wrong guesses on the confirm page also lock the settings forms', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    guessWrongOnConfirmPage(PasswordGuesses::WRONG_GUESSES);

    Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'new-password-123')
        ->set('password_confirmation', 'new-password-123')
        ->call('updatePassword')
        ->assertHasErrors(['current_password' => PasswordGuesses::lockedMessage(PasswordGuesses::DECAY_SECONDS)]);

    expect(Hash::check('password', $user->fresh()->password ?? ''))->toBeTrue();
});

test('one account\'s wrong guesses do not lock another account', function (): void {
    $this->actingAs(User::factory()->create());
    guessWrongOnConfirmPage(PasswordGuesses::WRONG_GUESSES);

    $this->actingAs(User::factory()->create())
        ->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasNoErrors();
});

test('a guess past the limit is refused without a check, as when requests arrive at once', function (): void {
    $user = User::factory()->create();
    $guesses = app(PasswordGuesses::class);

    for ($guess = 1; $guess <= PasswordGuesses::WRONG_GUESSES; $guess++) {
        expect($guesses->check($user, 'wrong-password'))->toBeFalse();
    }

    // Requests that passed lockedFor() before the others were counted.
    expect($guesses->check($user, 'password'))->toBeFalse();
});
