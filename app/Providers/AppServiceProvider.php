<?php declare(strict_types=1);

namespace App\Providers;

use App\Health\LastSuccessfulScrapeCheck;
use App\Services\Scraper\HtmlScraper;
use App\Services\Scraper\Scraper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\CpuLoadHealthCheck\CpuLoadCheck;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;
use Spatie\SecurityAdvisoriesHealthCheck\SecurityAdvisoriesCheck;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Scraper::class, HtmlScraper::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerHealthChecks();
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function registerHealthChecks(): void
    {
        Health::checks([
            EnvironmentCheck::new(),
            DebugModeCheck::new(),
            CacheCheck::new(),
            DatabaseCheck::new(),
            ScheduleCheck::new(),
            UsedDiskSpaceCheck::new(),
            CpuLoadCheck::new(),
            SecurityAdvisoriesCheck::new(),
            LastSuccessfulScrapeCheck::new()
                ->warnAfterHours(48)
                ->failAfterHours(96),
        ]);
    }
}
