<?php declare(strict_types=1);

use App\Console\Commands\DispatchDailyDigestsCommand;
use App\Console\Commands\PruneOldChecksCommand;
use App\Console\Commands\RecheckActiveShopsCommand;
use App\Console\Commands\RefreshCheckjebonDatasetCommand;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\CheckTokenForAnyScope;
use Zae\StrictTransportSecurity\Middleware\L5\StrictTransportSecurity;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(RecheckActiveShopsCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(RefreshCheckjebonDatasetCommand::class)
            ->dailyAt('08:00')
            ->timezone('Europe/Amsterdam')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(PruneOldChecksCommand::class)
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(DispatchDailyDigestsCommand::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // `append()`, not `web()`: the security headers must land on API,
        // webhook and MCP responses too, not only the web group.
        $middleware->append([
            SecurityHeaders::class,
            StrictTransportSecurity::class,
        ]);

        // Laravel 11+ stopped aliasing Passport's middleware, and the MCP
        // route needs `scopes` to enforce `mcp:use`. Without it `auth:api`
        // accepts any Passport token, for any client, on every tool.
        $middleware->alias([
            'scopes' => CheckToken::class,
            'scope' => CheckTokenForAnyScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // `oauth_clients.id` is a native uuid, so on Postgres a malformed
        // `client_id` raises SQLSTATE 22P02 inside Passport's own controllers
        // and escapes as a 500 on public endpoints. SQLite casts silently, so
        // no test against the default driver would ever see it.
        //
        // Narrowed three ways rather than on the route alone: the SQLSTATE,
        // a Passport route, and the failing statement actually touching
        // `oauth_clients`. Without that last check a second uuid parameter on
        // one of these routes would be reported as a bad `client_id`.
        $exceptions->render(function (QueryException $e, Request $request): ?Response {
            $routeName = $request->route()?->getName() ?? '';

            $isMalformedUuid = $e->getCode() === '22P02';
            $isPassportRoute = str_starts_with($routeName, 'passport.');
            $isClientLookup = str_contains($e->getSql(), 'oauth_clients');

            if (! $isMalformedUuid || ! $isPassportRoute || ! $isClientLookup) {
                return null;
            }

            return response('The client_id is not a valid client identifier.', 400);
        });
    })->create();
