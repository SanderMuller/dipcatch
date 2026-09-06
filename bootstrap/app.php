<?php declare(strict_types=1);

use App\Console\Commands\DispatchDailyDigestsCommand;
use App\Console\Commands\PruneOldChecksCommand;
use App\Console\Commands\RecheckActiveShopsCommand;
use App\Console\Commands\RefreshCheckjebonDatasetCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\CheckTokenForAnyScope;
use SanderMuller\QueueInsights\Console\QueueInsightsSnapshotCommand;
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

        $schedule->command(QueueInsightsSnapshotCommand::class)
            ->everyMinute();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
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
        //
    })->create();
