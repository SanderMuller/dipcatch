<?php declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Widgets\ActiveDropsTableWidget;
use App\Filament\App\Widgets\RecentNotificationsTableWidget;
use App\Filament\App\Widgets\SavingsByMonthChartWidget;
use App\Filament\App\Widgets\StatsOverviewWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->brandLogo(fn (): View => view('filament.partials.brand'))
            ->favicon(asset('favicon.png'))
            ->path('app')
            ->viteTheme('resources/css/filament/app/theme.css')
            ->authGuard('web')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->databaseNotifications()
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([
                AccountWidget::class,
                StatsOverviewWidget::class,
                ActiveDropsTableWidget::class,
                RecentNotificationsTableWidget::class,
                SavingsByMonthChartWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureEmailIsVerified::class,
            ])
            // Inject a tiny fire-and-forget JS snippet that POSTs the
            // browser-detected IANA timezone to the auto-detect endpoint on
            // first authenticated page load. The view short-circuits if the
            // user already has timezone_detected_at set.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): View => view('filament.app.timezone-autodetect'),
            );
    }
}
