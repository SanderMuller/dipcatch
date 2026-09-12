<?php declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The baseline security headers. See references/laravel-security-canon.md.
 *
 * Two things are deliberately NOT here, because both need per-app tuning: a
 * Content-Security-Policy, and no-cache on authenticated responses. An app that
 * is framed on purpose also has to relax X-Frame-Options per allowed origin.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // A header on a redirect or a download is noise, and a streamed
        // response may already have flushed its headers.
        if ($response instanceof RedirectResponse || $response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
