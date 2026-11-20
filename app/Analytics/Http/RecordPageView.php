<?php declare(strict_types=1);

namespace App\Analytics\Http;

use App\Analytics\Contracts\ResolvesCountry;
use App\Analytics\Contracts\ResolvesSection;
use App\Analytics\Contracts\TrackableUser;
use App\Analytics\DailySalt;
use App\Analytics\Models\PageView;
use App\Analytics\SignupSource;
use App\Analytics\UserAgent;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use SanderMuller\FluentValidation\FluentRule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnexpectedValueException;

/**
 * Counts a page load without JavaScript, cookies or a stored IP address.
 *
 * `handle()` decides and collects the values once the response exists, and
 * keeps them on the request. `terminate()` writes the row after the response
 * went out. The middleware itself holds no state, so Octane cannot carry a
 * value from one request into the next.
 */
final class RecordPageView
{
    public const string ATTRIBUTE = 'analytics.page_view';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config()->boolean('analytics.enabled', true)) {
            return $response;
        }

        try {
            $route = $request->route();

            if (! $route instanceof Route || ! $this->counts($request, $response, $route)) {
                return $response;
            }

            $values = $this->values($request, $route);
            $request->attributes->set(self::ATTRIBUTE, $values);

            $this->rememberSignupSource($request, $values);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $values = $request->attributes->get(self::ATTRIBUTE);

        if (! is_array($values)) {
            return;
        }

        try {
            PageView::query()->insert($values);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function counts(Request $request, Response $response, Route $route): bool
    {
        if (! $request->isMethod('GET') || $this->isPrefetch($request)) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));

        if (! str_starts_with($contentType, 'text/html')) {
            return false;
        }

        $name = $route->getName();

        if ($name !== null && Str::is($this->stringList('analytics.excluded_routes'), $name)) {
            return false;
        }

        return ! UserAgent::isBot($request->userAgent());
    }

    private function isPrefetch(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (str_contains(strtolower((string) $request->headers->get($header, '')), 'prefetch')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Request $request, Route $route): array
    {
        $now = now()->toImmutable();
        $user = $request->user();
        $userAgent = (string) $request->userAgent();
        $utm = $this->utmValues($request);

        return [
            PageView::VIEWED_AT => $now,
            PageView::SECTION => $this->resolver('analytics.section_resolver', ResolvesSection::class)->section($request)->value,
            PageView::ROUTE_NAME => $this->limit($route->getName(), 120),
            PageView::PATH => $this->limit($this->path($request, $route), 255) ?? '/',
            PageView::REFERRER_HOST => $this->referrerHost($request),
            PageView::UTM_SOURCE => $utm['utm_source'],
            PageView::UTM_MEDIUM => $utm['utm_medium'],
            PageView::UTM_CAMPAIGN => $utm['utm_campaign'],
            PageView::COUNTRY => $this->resolver('analytics.country_resolver', ResolvesCountry::class)->country($request),
            PageView::DEVICE => UserAgent::device($userAgent)->value,
            PageView::IS_AUTHENTICATED => $user !== null,
            PageView::USER_ID => $this->linkedUserId($user),
            PageView::VISITOR_HASH => DailySalt::visitorHash($now, (string) $request->ip(), $userAgent, config()->string('app.url', '')),
        ];
    }

    /**
     * The real path only for routes listed as public; otherwise the route's
     * URI template, so a token or record id in the path is never stored.
     */
    private function path(Request $request, Route $route): string
    {
        $name = $route->getName();
        $isPublic = $name !== null && in_array($name, $this->stringList('analytics.public_path_routes'), true);
        $path = $isPublic ? $request->path() : $route->uri();

        return '/' . trim($path, '/');
    }

    private function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('Referer');

        if (! is_string($referrer) || $referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = $this->withoutWww(strtolower($host));

        if ($host === $this->withoutWww(strtolower($request->getHost()))) {
            return null;
        }

        return $this->limit($host, 120);
    }

    private function withoutWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * The UTM values that are plain strings; an array such as
     * `utm_source[]=x` fails validation and is dropped.
     *
     * @return array{utm_source: ?string, utm_medium: ?string, utm_campaign: ?string}
     */
    private function utmValues(Request $request): array
    {
        $keys = ['utm_source', 'utm_medium', 'utm_campaign'];
        $valid = Validator::make(
            $request->query->all(),
            array_fill_keys($keys, FluentRule::string()->nullable()),
        )->valid();

        $value = fn (string $key): ?string => isset($valid[$key]) && is_string($valid[$key]) ? $this->limit(trim($valid[$key]), 100) : null;

        return [
            'utm_source' => $value('utm_source'),
            'utm_medium' => $value('utm_medium'),
            'utm_campaign' => $value('utm_campaign'),
        ];
    }

    private function limit(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }

    private function linkedUserId(?Authenticatable $user): int|string|null
    {
        if (! $user instanceof TrackableUser || ! $user->allowsUsageTracking()) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function rememberSignupSource(Request $request, array $values): void
    {
        if ($request->user() !== null || ! $request->hasSession()) {
            return;
        }

        $key = config()->string('analytics.signup_source_session_key', 'signup_source');
        $session = $request->session();

        if ($session->has($key)) {
            return;
        }

        $source = SignupSource::fromArray($values);

        if (! $source->isEmpty()) {
            $session->put($key, $source->toArray());
        }
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private function resolver(string $configKey, string $contract): object
    {
        $resolver = app(config()->string($configKey));

        if (! $resolver instanceof $contract) {
            throw new UnexpectedValueException($configKey . ' must name a ' . $contract . '.');
        }

        return $resolver;
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        return array_values(array_filter(config()->array($key, []), is_string(...)));
    }
}
