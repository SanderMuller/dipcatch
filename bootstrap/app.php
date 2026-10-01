<?php declare(strict_types=1);

use App\Console\Commands\CategoriseProductsCommand;
use App\Console\Commands\DiscoverWebShopsCommand;
use App\Console\Commands\DispatchDailyDigestsCommand;
use App\Console\Commands\ImportBolFeedCommand;
use App\Console\Commands\PruneOldChecksCommand;
use App\Console\Commands\RecheckActiveShopsCommand;
use App\Console\Commands\RefreshBolOffersCommand;
use App\Console\Commands\RefreshCheckjebonDatasetCommand;
use App\Console\Commands\RetryReferenceShopsCommand;
use App\Console\Commands\RunAdapterCanaryCommand;
use App\Http\Middleware\RequireVerifiedEmailToAddCredentials;
use App\Http\Middleware\SecurityHeaders;
use App\Models\EmptySearch;
use App\Models\FailedShopPage;
use App\Services\BolApi\BolCatalogClient;
use App\Services\BolFeed\BolFeedDownloader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Console\PruneCommand;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Middleware\AuthenticateSession;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\CheckTokenForAnyScope;
use Sentry\Laravel\Integration;
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
            ->dailyAt('07:30')
            ->timezone('Europe/Amsterdam')
            ->withoutOverlapping()
            ->onOneServer();

        // After the checkjebon refresh, which keeps chains with prices only.
        // Needs the IP address it runs from whitelisted at bol.com.
        $schedule->command(ImportBolFeedCommand::class)
            ->dailyAt('08:00')
            ->timezone('Europe/Amsterdam')
            ->when(BolFeedDownloader::configured(...))
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // Keeps suggested bol.com prices a day old at most where the feed
        // import cannot run. No IP whitelist: the API answers anywhere.
        $schedule->command(RefreshBolOffersCommand::class)
            ->dailyAt('08:30')
            ->timezone('Europe/Amsterdam')
            ->when(BolCatalogClient::configured(...))
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // Searches without results and pages we could not read are kept six
        // months after the last one.
        $schedule->command(PruneCommand::class, ['--model' => [EmptySearch::class, FailedShopPage::class]])
            ->dailyAt('03:50')
            ->timezone('Europe/Amsterdam')
            ->onOneServer();

        $schedule->command(RunAdapterCanaryCommand::class)
            ->dailyAt('05:15')
            ->timezone('Europe/Amsterdam')
            ->withoutOverlapping()
            ->onOneServer();

        // Spatie's registered ScheduleCheck fails until this heartbeat runs,
        // so without it the first health mail is a false alarm.
        $schedule->command('health:schedule-check-heartbeat')
            ->everyMinute()
            ->onOneServer();

        $schedule->command('health:check')
            ->dailyAt('05:45')
            ->timezone('Europe/Amsterdam')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(PruneOldChecksCommand::class)
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(CategoriseProductsCommand::class)
            ->dailyAt('04:30')
            ->timezone('Europe/Amsterdam')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(DiscoverWebShopsCommand::class)
            ->dailyAt('04:40')
            ->timezone('Europe/Amsterdam')
            ->withoutOverlapping()
            ->onOneServer();

        // Weekly, and deliberately slow: a shop that refuses us is not going
        // to change its mind on a Tuesday afternoon, and every attempt is a
        // request to a host that already said no.
        $schedule->command(RetryReferenceShopsCommand::class)
            ->weeklyOn(1, '04:45')
            ->timezone('Europe/Amsterdam')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command(DispatchDailyDigestsCommand::class)
            ->everyFiveMinutes()
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

        // `AuthenticateSession` ends every other session of an account whose
        // password hash changed. Claiming a squatted account resets the
        // password, and a Redis session is keyed by its id, not the user, so
        // deleting `sessions` rows cannot end the squatter's browser session.
        $middleware->web(append: [
            AuthenticateSession::class,
            RequireVerifiedEmailToAddCredentials::class,
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
        Integration::handles($exceptions);

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
    })
    ->create();
