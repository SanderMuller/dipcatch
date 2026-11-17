<?php declare(strict_types=1);

it('advertises the MCP URL as the protected resource', function (): void {
    $payload = $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->json();

    if (! is_array($payload)) {
        $this->fail('protected resource metadata was not JSON');
    }

    expect($payload['resource'] ?? null)->toBe(url('/mcp'))
        ->and($payload['authorization_servers'] ?? null)->toBeArray()
        ->and($payload['authorization_servers'] ?? null)->not->toBeEmpty();
});

it('advertises an authorization server ChatGPT can register against', function (): void {
    $resource = $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->json();

    $server = $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->json();

    if (! is_array($resource) || ! is_array($server)) {
        $this->fail('OAuth discovery documents were not JSON');
    }

    $servers = $resource['authorization_servers'] ?? null;

    if (! is_array($servers)) {
        $this->fail('authorization_servers was not a list');
    }

    expect($server)->toHaveKeys(['authorization_endpoint', 'token_endpoint', 'issuer', 'registration_endpoint', 'code_challenge_methods_supported', 'token_endpoint_auth_methods_supported'])
        ->and($server['authorization_endpoint'])->toBe(route('passport.authorizations.authorize'))
        ->and($server['token_endpoint'])->toBe(route('passport.token'))
        ->and($server['registration_endpoint'])->toBe(url('oauth/register'))
        ->and($server['issuer'])->toBeIn($servers)
        ->and($server['code_challenge_methods_supported'])->toContain('S256')
        ->and($server['token_endpoint_auth_methods_supported'])->toBeArray()
        ->and($server['token_endpoint_auth_methods_supported'])->toContain('none');

    $hasDcr = filled($server['registration_endpoint'] ?? null);
    $hasCimd = ($server['client_id_metadata_document_supported'] ?? false) === true;

    expect($hasDcr || $hasCimd)->toBeTrue();
});

it('keeps the nested protected-resource document on the MCP URL', function (): void {
    $payload = $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->json();

    if (! is_array($payload)) {
        $this->fail('nested protected resource metadata was not JSON');
    }

    expect($payload['resource'] ?? null)->toBe(url('/mcp'));
});

it('points a 401 at resource metadata when it sends WWW-Authenticate', function (): void {
    $challenge = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertUnauthorized()->headers->get('WWW-Authenticate');

    expect($challenge)->toBeString()
        ->and($challenge)->toContain('resource_metadata');
});

it('limits open client registration per address', function (): void {
    // The limiter lives in Redis, which outlives a test, so this run takes an
    // address of its own.
    $this->withServerVariables(['REMOTE_ADDR' => fake()->unique()->ipv4()]);

    $register = fn () => $this->postJson('oauth/register', [
        'client_name' => 'Flood',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ]);

    for ($attempt = 1; $attempt <= 30; $attempt++) {
        $register()->assertSuccessful();
    }

    $register()->assertTooManyRequests();
});

it('registers a client that sends the code back to a known assistant', function (string $redirectUri): void {
    $this->withServerVariables(['REMOTE_ADDR' => fake()->unique()->ipv4()]);

    $this->postJson('oauth/register', [
        'client_name' => 'Assistant',
        'redirect_uris' => [$redirectUri],
    ])->assertSuccessful();
})->with([
    'Claude' => 'https://claude.ai/api/mcp/auth_callback',
    'ChatGPT' => 'https://chatgpt.com/connector_platform_oauth_redirect',
    'a desktop client on loopback' => 'http://127.0.0.1:33418/callback',
    'Cursor' => 'cursor://anysphere.cursor-mcp/oauth/callback',
]);

it('refuses a client that sends the code anywhere else', function (string $redirectUri): void {
    $this->withServerVariables(['REMOTE_ADDR' => fake()->unique()->ipv4()]);

    $this->postJson('oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [$redirectUri],
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
})->with([
    'an unknown site' => 'https://attacker.example.test/cb',
    'a look-alike host' => 'https://claude.ai.attacker.example.test/cb',
    'an unknown custom scheme' => 'attacker://callback',
]);
