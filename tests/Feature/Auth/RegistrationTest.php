<?php declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('new users can register', function (): void {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect('/app');

    $this->assertAuthenticated();
});

test('one network can create only so many accounts an hour', function (): void {
    // Every account sends a verification mail to the
    // address it names, so unlimited sign-ups send mail to any stranger.
    Notification::fake();

    $register = fn (string $email) => $this->post(route('register.store'), [
        'name' => 'Sign Up',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    foreach (range(1, CreateNewUser::REGISTRATIONS_PER_HOUR) as $number) {
        $register("user{$number}@example.test")->assertSessionHasNoErrors();
        auth()->logout();
    }

    $register('one-too-many@example.test')->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'one-too-many@example.test')->exists())->toBeFalse();
    Notification::assertSentTimes(VerifyEmail::class, CreateNewUser::REGISTRATIONS_PER_HOUR);

    $this->travel(61)->minutes();

    $register('next-hour@example.test')->assertSessionHasNoErrors();
});
