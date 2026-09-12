<?php declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Server\Registrar;

/**
 * laravel/mcp's root well-known documents advertise `url('/')` and omit
 * `token_endpoint_auth_methods_supported`. ChatGPT's plugin OAuth needs the
 * MCP resource URL and the DCR auth method. Passport and DCR stay the package's.
 */
final class McpOauthDiscoveryController extends Controller
{
    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => $this->issuer(),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => url('oauth/register'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ]);
    }

    private function issuer(): string
    {
        $issuer = Config::get('mcp.authorization_server');

        return is_string($issuer) && $issuer !== '' ? $issuer : url('/');
    }
}
