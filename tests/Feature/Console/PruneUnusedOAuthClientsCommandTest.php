<?php declare(strict_types=1);

use App\Console\Commands\PruneUnusedOAuthClientsCommand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

function registeredClient(string $name): Client
{
    // As the MCP server's client registration creates one: public, no owner.
    return app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, ['https://claude.ai/api/mcp/auth_callback'], confidential: false);
}

test('it deletes registered clients nobody signed in with, once they had time to', function (): void {
    $user = User::factory()->create();
    $unused = registeredClient('unused');
    $signedIn = registeredClient('signed in');
    $codeIssued = registeredClient('code issued');
    app(ClientRepository::class)->createAuthorizationCodeGrantClient('first party', ['https://dipcatch.test/cb']);
    app(ClientRepository::class)->createAuthorizationCodeGrantClient('owned', ['https://claude.ai/cb'], confidential: false, user: $user);

    DB::table('oauth_access_tokens')->insert(['id' => str_repeat('a', 80), 'user_id' => $user->id, 'client_id' => $signedIn->id, 'revoked' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('oauth_auth_codes')->insert(['id' => str_repeat('b', 80), 'user_id' => $user->id, 'client_id' => $codeIssued->id, 'revoked' => false, 'expires_at' => now()]);

    $this->travel(PruneUnusedOAuthClientsCommand::KEEP_DAYS)->days();
    registeredClient('young');
    $this->travel(1)->minute();

    $this->artisan('dipcatch:prune-unused-oauth-clients')->assertSuccessful();

    expect(Client::query()->pluck('name')->sort()->values()->all())
        ->toBe(['code issued', 'first party', 'owned', 'signed in', 'young'])
        ->and($unused->fresh())->toBeNull();
});

test('it runs daily', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('dipcatch:prune-unused-oauth-clients');
});
