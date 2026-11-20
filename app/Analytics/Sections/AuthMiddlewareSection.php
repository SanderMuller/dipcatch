<?php declare(strict_types=1);

namespace App\Analytics\Sections;

use App\Analytics\Contracts\ResolvesSection;
use App\Analytics\Enums\PageViewSection;
use Illuminate\Http\Request;

/**
 * A page behind the `auth` middleware is part of the app; every other page,
 * login and register included, is marketing.
 */
final class AuthMiddlewareSection implements ResolvesSection
{
    public function section(Request $request): PageViewSection
    {
        $middleware = $request->route()?->gatherMiddleware() ?? [];

        foreach ($middleware as $name) {
            if (is_string($name) && ($name === 'auth' || str_starts_with($name, 'auth:'))) {
                return PageViewSection::App;
            }
        }

        return PageViewSection::Marketing;
    }
}
