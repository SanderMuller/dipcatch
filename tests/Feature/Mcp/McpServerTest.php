<?php declare(strict_types=1);

use App\Mcp\Servers\DipCatchServer;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Token;

/**
 * The endpoint tests only need the route's middleware stack, not a working
 * token exchange, so there is no Passport fixture here: building one means
 * asserting on Passport's own schema rather than on this app's guard.
 */
it('refuses an unauthenticated tool call', function (): void {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized();
});

it('advertises both oauth discovery documents', function (): void {
    $this->getJson('/.well-known/oauth-protected-resource')->assertOk();
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
});

it('answers 405 with an Allow header for a GET', function (): void {
    $this->get('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
});

it('guards the endpoint with the mcp:use scope, not merely with authentication', function (): void {
    // laravel/mcp advertises the scope and attaches it to registered clients,
    // but checks it nowhere. Without the `scopes` middleware any Passport
    // token, issued for anything, would reach every tool.
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'mcp' && in_array('POST', $route->methods(), true));

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain('auth:api')
        ->and($route?->gatherMiddleware())->toContain('scopes:mcp:use');
});

it('registers the scope middleware alias, since Laravel does not alias Passport for us', function (): void {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKey('scopes')
        ->and($aliases['scopes'])->toBe(CheckToken::class);
});

it('rate limits the endpoint per token owner', function (): void {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'mcp' && in_array('POST', $route->methods(), true));

    expect($route?->gatherMiddleware())->toContain('throttle:mcp');
});

it('exposes every tool under a readable name', function (): void {
    // The default is kebab-case of the class name with the Tool suffix left
    // on, so `list-products-tool`. Each tool sets #[Name] instead.
    $defaults = (new ReflectionClass(DipCatchServer::class))->getDefaultProperties();
    $declared = $defaults['tools'] ?? null;

    expect($declared)->toBeArray();

    $tools = [];

    foreach (is_array($declared) ? $declared : [] as $tool) {
        expect($tool)->toBeString();

        if (is_string($tool)) {
            $tools[] = $tool;
        }
    }

    expect($tools)->toHaveCount(8);

    foreach ($tools as $tool) {
        $name = app($tool)->name();

        expect($name)->toMatch('/^[a-z_]+$/')
            ->and($name)->not->toContain('tool');
    }
});
