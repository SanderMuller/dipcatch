<?php declare(strict_types=1);

namespace App\Analytics\Http;

use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentRule;
use Symfony\Component\HttpFoundation\Cookie;
use UnexpectedValueException;

/**
 * Lets a visitor object to being counted (GDPR Art. 21), or take that back.
 * The objection is a cookie, so it works without an account and is
 * remembered in this browser only.
 */
final class ObjectionController
{
    public const string STATUS = 'analytics.objection';

    public function store(Request $request): RedirectResponse
    {
        if ($request->hasSession()) {
            $request->session()->forget(config()->string('analytics.signup_source_session_key', 'signup_source'));
        }

        return $this->back($request, 'stored')
            ->withCookie(self::cookie(
                'analytics.visitor_cookie.objection_name',
                '1',
                now()->addDays(config()->integer('analytics.visitor_cookie.lifetime_days', 395)),
                $request->isSecure(),
            ))
            ->withCookie(self::expired('analytics.visitor_cookie.name'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        return $this->back($request, 'removed')
            ->withCookie(self::expired('analytics.visitor_cookie.objection_name'));
    }

    /**
     * Back to the return route on this host. The query values named in
     * `analytics.objection_keep_query` are kept when they are plain short
     * strings, so the page comes back in the language it was shown in.
     */
    private function back(Request $request, string $status): RedirectResponse
    {
        $keys = array_values(array_filter(config()->array('analytics.objection_keep_query', []), is_string(...)));

        try {
            $query = $request->validate(array_fill_keys($keys, FluentRule::string()->nullable()->alphaDash()->max(20)));
        } catch (ValidationException) {
            $query = [];
        }

        return redirect()
            ->to(route(config()->string('analytics.objection_return_route'), $query, absolute: false) . '#count-visits')
            ->with(self::STATUS, $status);
    }

    /**
     * A host-only cookie on `/`, the same scope `RecordPageView` sets the
     * visitor cookie with, so the session's domain and path settings never
     * make a delete miss it.
     */
    private static function cookie(string $nameKey, string $value, DateTimeInterface|int $expires, bool $secure = false): Cookie
    {
        return Cookie::create(self::cookieName($nameKey), $value, $expires, '/', null, $secure, true, false, Cookie::SAMESITE_LAX);
    }

    private static function expired(string $nameKey): Cookie
    {
        return self::cookie($nameKey, '', 1);
    }

    /**
     * @return non-empty-string
     */
    private static function cookieName(string $configKey): string
    {
        $name = config()->string($configKey);

        if ($name === '') {
            throw new UnexpectedValueException($configKey . ' must name a cookie.');
        }

        return $name;
    }
}
