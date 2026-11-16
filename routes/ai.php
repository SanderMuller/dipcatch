<?php declare(strict_types=1);

use App\Http\Controllers\McpOauthDiscoveryController;
use App\Mcp\Servers\DipCatchServer;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

// Loaded by laravel/mcp itself (McpServiceProvider resolves this path), so it
// is deliberately absent from bootstrap/app.php.

// Register first so laravel/mcp skips its root well-known routes (hasGetRoute).
// Nested `/.well-known/oauth-protected-resource/mcp` and DCR stay the package's.
Route::get('.well-known/oauth-protected-resource', [McpOauthDiscoveryController::class, 'protectedResource'])
    ->name('mcp.oauth.protected-resource');

Route::get('.well-known/oauth-authorization-server', [McpOauthDiscoveryController::class, 'authorizationServer'])
    ->name('mcp.oauth.authorization-server');

// Registration comes off so it can sit behind a limit. The package adds no
// middleware, and every request creates a client row. The route keeps the
// package's name, which discovery uses for `registration_endpoint`.
Mcp::oauthRoutes(registration: false);

Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware(ThrottleRequestsWithRedis::using('mcp-registration'))
    ->name('mcp.oauth.register');

Mcp::web('/mcp', DipCatchServer::class)
    // `auth:api` proves who the token belongs to; it does not prove the token
    // was issued for this. laravel/mcp advertises `mcp:use` in discovery and
    // attaches it to registered clients, but nothing checks it server-side —
    // without `scopes` any Passport token would reach every tool.
    //
    // `verified`: a token issued to an account that never proved its address
    // must not reach the tools — see RequireVerifiedEmailToAddCredentials.
    ->middleware(['auth:api', EnsureEmailIsVerified::class, 'scopes:mcp:use', 'throttle:mcp']);
