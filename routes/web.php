<?php declare(strict_types=1);

use App\Http\Controllers\AutoDetectTimezoneController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\PublicProductController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\UseCasePageController;
use App\Http\Middleware\MarketingLocale;
use App\Livewire\Products\ProductList;
use App\Livewire\Products\ProductShow;
use App\Support\UseCases;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Cashier\Http\Controllers\PaymentController as CashierPaymentController;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

// Cashier's own route registration is off (see AppServiceProvider): these
// carry the signature middleware unconditionally, so an unsigned or
// unverifiable webhook is refused rather than acted on.
Route::prefix(Config::string('cashier.path', 'stripe'))->name('cashier.')->group(function (): void {
    Route::post('webhook', [CashierWebhookController::class, 'handleWebhook'])
        ->middleware(VerifyWebhookSignature::class)
        // Stripe posts server to server and holds no session token; the
        // signature above is what authenticates it. Excluded here rather
        // than by path in bootstrap/app.php, which cannot read the
        // configured Cashier path — a custom CASHIER_PATH would then fail
        // every correctly signed webhook on CSRF.
        ->withoutMiddleware(PreventRequestForgery::class)
        ->name('webhook');

    Route::get('payment/{id}', [CashierPaymentController::class, 'show'])->name('payment');
});

Route::view('/', 'welcome')->middleware(MarketingLocale::class)->name('home');
Route::view('privacy', 'privacy')->middleware(MarketingLocale::class)->name('privacy');
Route::view('pricing', 'pricing')->middleware(MarketingLocale::class)->name('pricing');

// One landing page per repeat-purchase category. The slug is constrained to
// the configured set so an unknown one 404s in the router, and the page never
// renders empty.
Route::get('price-alerts/{slug}', UseCasePageController::class)
    ->where('slug', UseCases::slugPattern())
    ->middleware(MarketingLocale::class)
    ->name('use-case');

// Crawler-facing endpoints. Both drop the session middleware so the response
// carries no Set-Cookie: Cloudflare will not cache a response that sets one,
// and these are the two URLs where caching actually helps. The HSTS header
// appended in bootstrap/app.php stays on — it sets no cookie.
Route::get('sitemap.xml', SitemapController::class)
    ->withoutMiddleware([
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('sitemap');

Route::get('llms.txt', LlmsTxtController::class)
    ->withoutMiddleware([
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('llms');

// Advertised in the scraper's own user agent, so shop operators who look it
// up land on a page that explains the crawler. English only, no locale
// middleware: the readers are operators, not customers.
Route::view('bot', 'bot')->name('bot');

// Public product share page — no auth, throttled per IP, exact 32-char
// alphanumeric slug. Lives outside the auth+verified group so guests
// can hit it.
Route::get('p/{slug}', PublicProductController::class)
    ->where('slug', '[A-Za-z0-9]{32}')
    ->middleware(ThrottleRequestsWithRedis::using('public-product'))
    ->name('product.public');

Route::middleware(['auth', EnsureEmailIsVerified::class])->group(function (): void {

    Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('push/subscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    Route::get('billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('billing/portal', [BillingController::class, 'portal'])->name('billing.portal');

    Route::post('profile/timezone/auto-detect', AutoDetectTimezoneController::class)
        ->middleware(ThrottleRequestsWithRedis::using('auto-detect-timezone'))
        ->name('profile.timezone.auto-detect');
});

Route::middleware(ThrottleRequestsWithRedis::using('invitation'))->group(function (): void {
    Route::get('invite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invite/{token}', [InvitationController::class, 'redeem'])->name('invitation.redeem');
});

/*
|--------------------------------------------------------------------------
| Flux user-facing app  (specs/flux-user-app-migration.md)
|--------------------------------------------------------------------------
|
| Built behind `/next` until the cutover, which repoints this group at `/app`
| and deletes the Filament app panel. Every route the migration will need is
| registered here in one place: several phases become ready at the same time
| and would otherwise edit this file concurrently.
|
| Placeholders below are replaced by their real pages phase by phase. The
| middleware matches the Filament panel's authMiddleware exactly, so the
| access rules do not change at cutover.
|
*/
Route::prefix('next')
    ->name('app.')
    ->middleware(['auth', EnsureEmailIsVerified::class])
    ->group(function (): void {
        Route::view('/', 'next.placeholder')->name('dashboard');
        Route::livewire('products', ProductList::class)->name('products.index');
        Route::view('products/create', 'next.placeholder')->name('products.create');
        Route::view('products/create-manual', 'next.placeholder')->name('products.create-manual');
        Route::livewire('products/{product}', ProductShow::class)->name('products.show');
        Route::view('products/{product}/edit', 'next.placeholder')->name('products.edit');
        Route::view('billing', 'next.placeholder')->name('billing');
        Route::view('notifications', 'next.placeholder')->name('notifications');
        Route::view('connections', 'next.placeholder')->name('connections');
    });

require __DIR__ . '/settings.php';
