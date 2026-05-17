<?php declare(strict_types=1);

namespace App\Providers;

use App\Health\LastSuccessfulScrapeCheck;
use App\Jobs\CheckShopPrice;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ShopAdapter;
use App\Support\Config as DipConfig;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->singleton(AdapterResolver::class, function (): AdapterResolver {
            /** @var list<class-string<ShopAdapter>> $classes */
            $classes = (array) config('dipcatch.adapters', []);

            return new AdapterResolver(array_map(
                function (string $class): ShopAdapter {
                    $instance = $this->app->make($class);
                    assert($instance instanceof ShopAdapter);

                    return $instance;
                },
                $classes,
            ));
        });
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerHealthChecks();
        $this->registerRateLimiters();

        Gate::define('viewQueueInsights', static fn (): bool => app()->isLocal());

        Gate::define('retryFailedJobs', static fn (): bool => app()->isLocal());
    }

    protected function registerRateLimiters(): void
    {
        $perMinute = DipConfig::int('dipcatch.fetcher.rate_limit_per_minute', 12);

        // Queue middleware on `CheckShopPrice` keys on the offer's host so
        // background workers share the per-host budget that the synchronous
        // probe path also respects (the fetcher enforces the same limit
        // directly — see ShopFetcher::throttle).
        RateLimiter::for('shop-fetch', static function (CheckShopPrice $job) use ($perMinute): Limit {
            return Limit::perMinute($perMinute)->by('shop-fetch:' . $job->shop->host);
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(static fn (): ?Password => app()->isProduction()
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
