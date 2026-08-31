<?php declare(strict_types=1);

use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Filament\Admin\Resources\Invitations\Pages\ManageInvitations;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    clearRedisRateLimiter('invitation');
});

test('register route does not exist (invite-only)', function (): void {
    $this->get('/register')->assertNotFound();
});

test('show invitation page renders for a fresh token', function (): void {
    $invitation = Invitation::factory()->create();

    $this->get(route('invitation.show', ['token' => $invitation->token]))
        ->assertOk()
        ->assertSee($invitation->email);
});

test('show invitation 404s for unknown token', function (): void {
    $this->get(route('invitation.show', ['token' => 'nope']))->assertNotFound();
});

test('show invitation 404s for expired token', function (): void {
    $invitation = Invitation::factory()->expired()->create();

    $this->get(route('invitation.show', ['token' => $invitation->token]))->assertNotFound();
});

test('show invitation 404s for redeemed token', function (): void {
    $invitation = Invitation::factory()->redeemed()->create();

    $this->get(route('invitation.show', ['token' => $invitation->token]))->assertNotFound();
});

test('redeem creates user with derived currency, marks invitation, logs in', function (): void {
    $invitation = Invitation::factory()->create(['email' => 'newbie@dipcatch.test']);

    $this->withHeader('Accept-Language', 'nl-NL,nl;q=0.9')
        ->post(route('invitation.redeem', ['token' => $invitation->token]), [
            'name' => 'Newbie',
            'password' => 'super-secret-pass',
            'password_confirmation' => 'super-secret-pass',
        ])
        ->assertRedirect('/app');

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'newbie@dipcatch.test')->sole();
    expect($user->name)->toBe('Newbie')
        ->and($user->default_currency)->toBe('EUR')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($invitation->fresh()->redeemed_at)->not->toBeNull();
});

test('redeem rejects already-redeemed token', function (): void {
    $invitation = Invitation::factory()->redeemed()->create();

    $this->post(route('invitation.redeem', ['token' => $invitation->token]), [
        'name' => 'X',
        'password' => 'super-secret-pass',
        'password_confirmation' => 'super-secret-pass',
    ])->assertNotFound();
});

test('redeem rejects with 404 when token gets redeemed concurrently after validation', function (): void {
    $invitation = Invitation::factory()->create();

    // Simulate a parallel request that won the race: mark the invitation
    // redeemed *after* the controller's outer resolveInvitation() check
    // would have passed. The lockForUpdate() inside the transaction must
    // re-validate and 404, instead of letting User::create reach the
    // unique:email validator (which would yield a confusing 422).
    Invitation::query()->where('id', $invitation->id)->update(['redeemed_at' => now()]);

    $this->post(route('invitation.redeem', ['token' => $invitation->token]), [
        'name' => 'X',
        'password' => 'super-secret-pass',
        'password_confirmation' => 'super-secret-pass',
    ])->assertNotFound();
});

test('redeem requires matching password confirmation', function (): void {
    $invitation = Invitation::factory()->create();

    $this->post(route('invitation.redeem', ['token' => $invitation->token]), [
        'name' => 'X',
        'password' => 'super-secret-pass',
        'password_confirmation' => 'mismatch',
    ])->assertSessionHasErrors('password');
});

test('invitation routes are throttled at 30 requests per minute per IP', function (): void {
    $invitation = Invitation::factory()->create();
    $url = route('invitation.show', ['token' => $invitation->token]);

    // Burn the bucket: 30 successful hits, the 31st must be denied.
    for ($i = 0; $i < 30; $i++) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertStatus(429);
});

test('admin creating an invitation in Filament dispatches email and persists row', function (): void {
    Mail::fake();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    InvitationResource::createInvitationFor(
        email: 'invitee@dipcatch.test',
        invitedById: $admin->id,
    );

    $this->assertDatabaseHas('invitations', [
        'email' => 'invitee@dipcatch.test',
        'invited_by' => $admin->id,
        'redeemed_at' => null,
    ]);

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('invitee@dipcatch.test'));
});

test('Filament invitation form rejects emails that already belong to a user', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['email' => 'already@dipcatch.test']);
    $this->actingAs($admin);

    livewire(ManageInvitations::class)
        ->callAction('create', ['email' => 'already@dipcatch.test'])
        ->assertHasActionErrors(['email']);

    expect(Invitation::query()->where('email', 'already@dipcatch.test')->count())->toBe(0);
});
