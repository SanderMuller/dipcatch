<?php declare(strict_types=1);

use App\Console\Commands\CategoriseProductsCommand;
use App\Console\Commands\DiscoverWebShopsCommand;
use App\Console\Commands\DispatchDailyDigestsCommand;
use App\Console\Commands\ImportBolFeedCommand;
use App\Console\Commands\LookUpBolOffersCommand;
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
        // Production scales to zero. Laravel Cloud wakes the App per due task,
        // from the `schedule:list` it stored at deploy, and stops it after the
        // sleep timeout even mid-command. So: nothing runs more often than
        // hourly; work that can outlast the timeout goes on the queue (the
        // bol.com feed import is the exception); times are UTC, because the
        // stored wake time does not follow a DST switch until the next deploy.
        // A run cut off by the sleep keeps its overlap lock until it expires,
        // so every lock expires well before the next run.

        $schedule->command(RecheckActiveShopsCommand::class)
            ->hourly()
            ->withoutOverlapping(15)
            ->onOneServer();

        $schedule->command(RefreshCheckjebonDatasetCommand::class)
            ->dailyAt('05:30')
            ->withoutOverlapping(60)
            ->onOneServer();

        // Needs the IP address it runs from whitelisted at bol.com. Still
        // runs on the App: the feed is too large for a queue job, so a run
        // that outlasts the sleep timeout is cut off.
        $schedule->command(ImportBolFeedCommand::class)
            ->dailyAt('06:00')
            ->when(BolFeedDownloader::configured(...))
            ->withoutOverlapping(60)
            ->onOneServer()
            ->runInBackground();

        // A product untouched since bol.com became a suggestion gets one too.
        $schedule->command(LookUpBolOffersCommand::class)
            ->dailyAt('07:00')
            ->when(BolCatalogClient::configured(...))
            ->withoutOverlapping(60)
            ->onOneServer();

        // No IP whitelist: the API answers anywhere.
        $schedule->command(RefreshBolOffersCommand::class)
            ->dailyAt('06:30')
            ->when(BolCatalogClient::configured(...))
            ->withoutOverlapping(60)
            ->onOneServer();

        // Searches without results and pages we could not read are kept six
        // months after the last one.
        $schedule->command(PruneCommand::class, ['--model' => [EmptySearch::class, FailedShopPage::class]])
            ->dailyAt('01:50')
            ->onOneServer();

        $schedule->command(RunAdapterCanaryCommand::class)
            ->dailyAt('03:15')
            ->withoutOverlapping(60)
            ->onOneServer();

        // Spatie's registered ScheduleCheck fails until this heartbeat runs,
        // so without it the first health mail is a false alarm. Hourly, and
        // the check allows for that; see AppServiceProvider.
        $schedule->command('health:schedule-check-heartbeat')
            ->hourly()
            ->onOneServer();

        // Half an hour after the canary, whose jobs it reads.
        $schedule->command('health:check')
            ->dailyAt('03:45')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command(PruneOldChecksCommand::class)
            ->dailyAt('03:00')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command(CategoriseProductsCommand::class)
            ->dailyAt('02:30')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command(DiscoverWebShopsCommand::class)
            ->dailyAt('02:40')
            ->withoutOverlapping(60)
            ->onOneServer();

        // Weekly, and deliberately slow: a shop that refuses us is not going
        // to change its mind on a Tuesday afternoon, and every attempt is a
        // request to a host that already said no.
        $schedule->command(RetryReferenceShopsCommand::class)
            ->weeklyOn(1, '02:45')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command(DispatchDailyDigestsCommand::class)
            ->hourly()
            ->withoutOverlapping(15)
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
