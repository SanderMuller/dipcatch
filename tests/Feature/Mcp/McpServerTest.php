<?php declare(strict_types=1);

use App\Mcp\Servers\DipCatchServer;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Passport;
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

it('refuses a token that carries no mcp:use scope', function (): void {
    // The middleware-string assertion above proves the guard is attached.
    // This proves it actually rejects: `actingAs` on the server bypasses the
    // HTTP stack entirely, so without this nothing exercised Passport at all.
    Passport::actingAs(User::factory()->create(), []);

    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertForbidden();
});

it('accepts a token that carries it', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk();
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

/**
 * A driver error carrying a real SQLSTATE. PDOException stores the code on
 * Exception's protected property, so it can only be set from a subclass.
 */
function sqlstate(string $code): PDOException
{
    return new class ($code) extends PDOException {
        public function __construct(string $sqlstate)
        {
            parent::__construct('invalid input syntax for type uuid');
            $this->code = $sqlstate;
        }
    };
}

function passportRequest(string $path = '/oauth/authorize', string $name = 'passport.authorizations.authorize'): Request
{
    $request = Request::create($path);

    $request->setRouteResolver(fn (): Route => tap(
        new Route(['GET'], ltrim($path, '/'), []),
        fn (Route $route) => $route->name($name),
    ));

    return $request;
}

function clientLookupFailure(string $sqlstate, string $sql = 'select * from "oauth_clients" where "id" = ?'): QueryException
{
    return new QueryException('pgsql', $sql, [], sqlstate($sqlstate));
}

it('turns a malformed client_id into a 400 rather than a 500', function (): void {
    // oauth_clients.id is a native uuid, so on Postgres a non-uuid raises
    // SQLSTATE 22P02 inside Passport and escapes as a 500 on a public
    // endpoint. Reproduced against PostgreSQL 17 before writing the handler;
    // SQLite casts silently, so the behaviour itself is Postgres-only.
    $handled = app(ExceptionHandler::class)->render(passportRequest(), clientLookupFailure('22P02'));

    expect($handled->getStatusCode())->toBe(400);
});

it('covers the token routes, not only authorize', function (): void {
    // Matching only the authorize route leaves POST /oauth/token answering
    // 500 for the same malformed client_id.
    foreach ([
        ['/oauth/token', 'passport.token'],
        ['/oauth/token/refresh', 'passport.token.refresh'],
        ['/oauth/authorize', 'passport.authorizations.approve'],
    ] as [$path, $name]) {
        $handled = app(ExceptionHandler::class)->render(
            passportRequest($path, $name),
            clientLookupFailure('22P02'),
        );

        expect($handled->getStatusCode())->toBe(400);
    }
});

it('does not claim a bad client_id when some other uuid on the route is malformed', function (): void {
    // Keying on the SQLSTATE and the route alone would report any uuid cast
    // failure on a Passport route as the client's identifier being wrong.
    $handled = app(ExceptionHandler::class)->render(
        passportRequest(),
        clientLookupFailure('22P02', 'select * from "oauth_auth_codes" where "id" = ?'),
    );

    expect($handled->getStatusCode())->toBe(500);
});

it('leaves non-Passport routes alone', function (): void {
    $handled = app(ExceptionHandler::class)->render(
        passportRequest('/', 'home'),
        clientLookupFailure('22P02'),
    );

    expect($handled->getStatusCode())->toBe(500);
});

it('leaves every other database error alone', function (): void {
    // Narrow on purpose: a database fault that is not a malformed uuid must
    // stay a 500 rather than be dressed up as the client's mistake.
    $handled = app(ExceptionHandler::class)->render(
        passportRequest(),
        clientLookupFailure('08006'),
    );

    expect($handled->getStatusCode())->toBe(500);
});

it('lets a well-formed client_id through to Passport', function (): void {
    // An unknown but well-formed client is Passport's business, not ours.
    $response = $this->get('/oauth/authorize?client_id=' . Str::uuid() . '&redirect_uri=https://example.test&response_type=code');

    // Was `not->toBe(400)`, which also passes on a 500 — and this is the only
    // test that reaches the real authorize route rather than rendering the
    // consent blade with fabricated parameters.
    expect($response->getStatusCode())->toBeLessThan(500);
});
