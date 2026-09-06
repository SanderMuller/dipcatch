<?php declare(strict_types=1);

namespace App\Providers;

use App\Actions\Suggestions\SuggestShops;
use App\Health\BillingConfigurationCheck;
use App\Health\CheckjebonFreshnessCheck;
use App\Health\LastSuccessfulScrapeCheck;
use App\Health\StripeWebhookSecretCheck;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ShopAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;
use Laravel\Passport\Passport;
use Spatie\CpuLoadHealthCheck\CpuLoadCheck;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;
use Spatie\SecurityAdvisoriesHealthCheck\SecurityAdvisoriesCheck;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One suggestion computation per request: the product page renders
        // the suggestions component twice, and the catalogue scan is the
        // expensive part.
        $this->app->scoped(SuggestShops::class);

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
        $this->configureBilling();

        Gate::define('viewQueueInsights', static fn (): bool => app()->isLocal());

        Gate::define('retryFailedJobs', static fn (): bool => app()->isLocal());
    }

    protected function configureBilling(): void
    {
        // Keep Pro through Stripe's dunning retries: a declined card is
        // usually an expired card, not a decision to stop paying. Stripe
        // ends the subscription when the retries run out, and that does
        // drop the account.
        Cashier::keepPastDueSubscriptionsActive();

        // Per token owner, falling back to IP for an unauthenticated probe.
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // The screen a user sees when an assistant asks for access to their
        // DipCatch account.
        Passport::authorizationView(
            fn (array $parameters): Response => response()->view('mcp.authorize', $parameters),
        );

        // Cashier attaches its signature middleware only when a secret is
        // set, so a deployment that forgets STRIPE_WEBHOOK_SECRET accepts
        // forged billing events. routes/web.php registers the same endpoints
        // with verification always on, which fails closed instead.
        Cashier::ignoreRoutes();
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
            StripeWebhookSecretCheck::new(),
            BillingConfigurationCheck::new(),
            LastSuccessfulScrapeCheck::new()
                ->warnAfterHours(48)
                ->failAfterHours(96),
            CheckjebonFreshnessCheck::new()
                ->warnAfterHours(48)
                ->failAfterHours(96),
        ]);
    }
}
