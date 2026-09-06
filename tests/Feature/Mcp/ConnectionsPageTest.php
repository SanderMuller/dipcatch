<?php declare(strict_types=1);

use App\Filament\App\Pages\Connections;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('app');
});

function connectToken(User $user, string $client = 'Claude'): Token
{
    $record = new Client();
    $record->forceFill([
        'id' => (string) Str::uuid(),
        'name' => $client,
        'redirect_uris' => ['https://example.test/callback'],
        'grant_types' => ['authorization_code'],
        'revoked' => false,
    ])->save();

    $token = Token::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'client_id' => $record->getKey(),
        'scopes' => ['mcp:use'],
        'revoked' => false,
        'expires_at' => now()->addDay(),
    ]);

    RefreshToken::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'access_token_id' => $token->getKey(),
        'revoked' => false,
        'expires_at' => now()->addMonth(),
    ]);

    return $token;
}

function tokenId(Token $token): string
{
    $key = $token->getKey();

    return is_scalar($key) ? (string) $key : '';
}

test('a user sees only their own connections', function (): void {
    $me = User::factory()->create();
    connectToken($me, 'Mine');
    connectToken(User::factory()->create(), 'Theirs');

    $this->actingAs($me);

    livewire(Connections::class)
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

test('the endpoint address is shown so a connector can be set up', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(Connections::class)->assertOk()->assertSee(url('/mcp'));
});

test('disconnecting revokes the access token and its refresh token', function (): void {
    // Revoking only the access token leaves the refresh token able to mint a
    // new one, so the connection would silently return.
    $me = User::factory()->create();
    $token = connectToken($me);

    $this->actingAs($me);

    livewire(Connections::class)->call('revoke', tokenId($token));

    expect($token->fresh()?->revoked)->toBeTrue()
        ->and(RefreshToken::query()->where('access_token_id', $token->getKey())->first()?->revoked)->toBeTrue();
});

test('a user cannot revoke someone elses connection', function (): void {
    $theirs = connectToken(User::factory()->create());

    $this->actingAs(User::factory()->create());

    livewire(Connections::class)->call('revoke', tokenId($theirs));

    expect($theirs->fresh()?->revoked)->toBeFalse();
});
