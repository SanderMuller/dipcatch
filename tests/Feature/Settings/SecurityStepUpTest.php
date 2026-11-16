<?php declare(strict_types=1);

use App\Livewire\Settings\Security;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/*
 * Security audit lead 2: `password.confirm` guards the settings/security page
 * request only. These tests send the component actions as real Livewire update
 * requests, because `Livewire::test()` skips the route's persistent middleware
 * and would pass whichever way the step-up is enforced.
 */

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

/**
 * Loads the security page as the given user, with the password just confirmed,
 * and returns the snapshot of its Security component.
 */
function securityPageSnapshot(User $user): string
{
    $html = test()->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => Carbon::now()->getTimestamp()])
        ->get(route('security.edit'))
        ->assertOk()
        ->getContent();

    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $html, $matches);

    $snapshot = array_find(
        array_map(fn (string $raw): string => html_entity_decode($raw, ENT_QUOTES), $matches[1]),
        fn (string $json): bool => (json_decode($json, true)['memo']['name'] ?? null) === 'settings.security',
    );

    expect($snapshot)->toBeString();

    return (string) $snapshot;
}

/**
 * Sends the calls to the Livewire update endpoint as the current session.
 *
 * @param  list<array{method: string, params: list<mixed>}>  $calls
 */
function callSecurityActions(string $snapshot, array $calls): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])
        ->postJson(EndpointResolver::updatePath(), [
            '_token' => csrf_token(),
            'components' => [
                ['snapshot' => $snapshot, 'updates' => [], 'calls' => $calls],
            ],
        ]);
}

test('a confirmed session turns off two factor through the update endpoint', function (): void {
    // The control for the tests below: without it, a request the endpoint
    // rejected for an unrelated reason would make them pass.
    $owner = User::factory()->withTwoFactor()->create();

    $snapshot = securityPageSnapshot($owner);

    callSecurityActions($snapshot, [['method' => 'disable', 'params' => []]])->assertOk();

    expect($owner->fresh()?->two_factor_confirmed_at)->toBeNull();
});

test('another account\'s confirmed snapshot cannot turn off two factor in an unconfirmed session', function (): void {
    $intruder = User::factory()->create();
    $owner = User::factory()->withTwoFactor()->create();

    $snapshot = securityPageSnapshot($intruder);

    $this->actingAs($owner);
    session()->forget('auth.password_confirmed_at');

    callSecurityActions($snapshot, [['method' => 'disable', 'params' => []]]);

    expect($owner->fresh()?->two_factor_confirmed_at)->not->toBeNull()
        ->and($owner->fresh()?->two_factor_secret)->not->toBeNull();
});

test('an unconfirmed session cannot read the existing two factor secret', function (): void {
    $intruder = User::factory()->create();
    // A distinctive value: the factory's 'secret' would match unrelated text.
    $secret = 'OWNERSECRETJ55R';
    $owner = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);

    $snapshot = securityPageSnapshot($intruder);

    $this->actingAs($owner);
    session()->forget('auth.password_confirmed_at');

    $response = callSecurityActions($snapshot, [['method' => 'enable', 'params' => []]]);

    expect((string) $response->getContent())->not->toContain($secret);
});

test('another account\'s confirmed snapshot cannot delete a passkey in an unconfirmed session', function (): void {
    $intruder = User::factory()->create();
    $owner = User::factory()->create();
    $passkey = $owner->passkeys()->create([
        'name' => 'Owner key',
        'credential_id' => 'owner-credential',
        'credential' => ['publicKey' => 'placeholder'],
    ]);

    $snapshot = securityPageSnapshot($intruder);

    $this->actingAs($owner);
    session()->forget('auth.password_confirmed_at');

    callSecurityActions($snapshot, [
        ['method' => 'confirmDelete', 'params' => [$passkey->id]],
        ['method' => 'deletePasskey', 'params' => []],
    ]);

    $this->assertModelExists($passkey);
});

test('an open page cannot turn off two factor after the password confirmation expires', function (): void {
    $owner = User::factory()->withTwoFactor()->create();

    $snapshot = securityPageSnapshot($owner);

    $this->travel(config()->integer('auth.password_timeout') + 60)->seconds();

    callSecurityActions($snapshot, [['method' => 'disable', 'params' => []]]);

    expect($owner->fresh()?->two_factor_confirmed_at)->not->toBeNull();
});

test('an unconfirmed session is sent to confirm the password', function (string $method): void {
    $owner = User::factory()->withTwoFactor()->create();

    $this->actingAs($owner);

    Livewire::test(Security::class)
        ->call($method)
        ->assertRedirect(route('password.confirm'));

    expect($owner->fresh()?->two_factor_confirmed_at)->not->toBeNull();
})->with(['enable', 'disable']);
