<?php declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (): Factory|View => view('livewire.auth.login'));
        Fortify::verifyEmailView(fn (): Factory|View => view('livewire.auth.verify-email'));
        Fortify::twoFactorChallengeView(fn (): Factory|View => view('livewire.auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn (): Factory|View => view('livewire.auth.confirm-password'));
        Fortify::registerView(fn (): Factory|View => view('livewire.auth.register'));
        Fortify::resetPasswordView(fn (): Factory|View => view('livewire.auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn (): Factory|View => view('livewire.auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()) . '|' . $request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('invitation', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip() ?? 'unknown');
        });

        // Per-user budget for the browser timezone auto-detect POST. The JS
        // fires once per page load until timezone_detected_at is stamped,
        // so 30/min is generous for normal use and bounds a tab-storm. The
        // route this limiter serves is auth+verified, so $request->user()
        // is always set when the limiter callback runs.
        RateLimiter::for('auto-detect-timezone', function (Request $request) {
            $user = $request->user();
            assert($user instanceof User);

            return Limit::perMinute(30)->by($user->id);
        });

        // Per-IP budget for the public product share page. Bounded to
        // protect against scraping the unguessable-slug URL space; 120/min
        // is well above legitimate viewing patterns (chat unfurl + a few
        // refreshes) but low enough to bite a crawler. Public route — no
        // user to key on, IP is the only signal.
        RateLimiter::for('public-product', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip() ?? 'unknown');
        });
    }
}
