<?php declare(strict_types=1);

use App\Mcp\Servers\DipCatchServer;
use Laravel\Mcp\Facades\Mcp;

// Loaded by laravel/mcp itself (McpServiceProvider resolves this path), so it
// is deliberately absent from bootstrap/app.php.

Mcp::oauthRoutes();

Mcp::web('/mcp', DipCatchServer::class)
    // `auth:api` proves who the token belongs to; it does not prove the token
    // was issued for this. laravel/mcp advertises `mcp:use` in discovery and
    // attaches it to registered clients, but nothing checks it server-side —
    // without `scopes` any Passport token would reach every tool.
    ->middleware(['auth:api', 'scopes:mcp:use', 'throttle:mcp']);
