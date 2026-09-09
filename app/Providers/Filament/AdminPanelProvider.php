<?php declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Widgets\OperationsOverviewWidget;
use App\Filament\Admin\Widgets\RevenueOverviewWidget;
use App\Filament\Admin\Widgets\ShopsNeedingAttentionWidget;
use App\Filament\Admin\Widgets\SubscriptionOverviewWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use ShuvroRoy\FilamentSpatieLaravelHealth\FilamentSpatieLaravelHealthPlugin;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // The only panel left after the user-facing app moved to Flux, so
            // it carries the default marker the deleted app panel used to hold.
            ->default()
            ->id('admin')
            ->brandName('DipCatch')
            // A partial of its own, styled inline: this panel compiles no Vite
            // theme, so the app panel's `h-8 w-8` utilities do not exist in its
            // CSS and the 512px logo rendered at full size over the sidebar.
            ->brandLogo(fn (): View => view('filament.partials.brand-admin'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.png'))
            ->path('admin')
            ->authGuard('web')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            ->widgets([
                AccountWidget::class,
                OperationsOverviewWidget::class,
                SubscriptionOverviewWidget::class,
                RevenueOverviewWidget::class,
                ShopsNeedingAttentionWidget::class,
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
            ->plugins([
                FilamentSpatieLaravelHealthPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureEmailIsVerified::class,
            ]);
    }
}
