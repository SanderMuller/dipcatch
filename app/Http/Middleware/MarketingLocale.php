<?php declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentRule;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * Resolves the locale of the public marketing pages from `?lang=` only.
 *
 * The bare URL always renders the fixed default (English), so crawlers,
 * caches and humans see one stable page. Nothing is written to the session
 * or a cookie: the two `?lang=` values are the only other representations.
 *
 * The previous locale is restored in a `finally` block, so a Dutch request
 * cannot leak `nl` into the next request served by the same Octane worker,
 * not even when the request threw.
 */
final class MarketingLocale
{
    /** The locales the marketing pages are published in. */
    public const array LOCALES = ['en', 'nl'];

    /**
     * The locale the request asks for, or null when it asks for none.
     *
     * A value that is not a published locale (`?lang=fr`, `?lang=<script>`,
     * `?lang[]=nl`) is treated as absent, so it never reaches the page.
     */
    public static function requested(Request $request): ?string
    {
        try {
            $validated = $request->validate([
                'lang' => FluentRule::string()->nullable()->in(self::LOCALES),
            ]);
        } catch (ValidationException) {
            return null;
        }

        $lang = $validated['lang'] ?? null;

        return is_string($lang) ? $lang : null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $previous = App::getLocale();

        App::setLocale(self::requested($request) ?? config()->string('app.locale'));

        try {
            $response = $next($request);

            if (! $response instanceof Response) {
                throw new UnexpectedValueException('Marketing route middleware expects a Response.');
            }

            return $response;
        } finally {
            App::setLocale($previous);
        }
    }
}
