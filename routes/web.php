<?php declare(strict_types=1);

use App\Http\Controllers\AutoDetectTimezoneController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\OpenaiAppsChallengeController;
use App\Http\Controllers\PublicProductController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ShopPageController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\UseCasePageController;
use App\Http\Middleware\MarketingLocale;
use App\Livewire\Billing\BillingPage;
use App\Livewire\Connections\ConnectionsPage;
use App\Livewire\Dashboard;
use App\Livewire\Products\CreateProductFromUrl;
use App\Livewire\Products\CreateProductManual;
use App\Livewire\Products\EditProduct;
use App\Livewire\Products\ProductList;
use App\Livewire\Products\ProductShow;
use App\Livewire\Settings\NotificationPreferences;
use App\Support\ShopPages;
use App\Support\UseCases;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\RedirectResponse;
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
Route::view('support', 'support')->middleware(MarketingLocale::class)->name('support');
Route::view('terms-of-service', 'terms')->middleware(MarketingLocale::class)->name('terms');

// `/privacy-policy` is the address given to Stripe and to anyone who guessed
// the conventional path. One canonical page, so it redirects rather than
// rendering a second copy. The locale query rides along.
Route::get('privacy-policy', fn (): RedirectResponse => redirect()->route('privacy', request()->query(), 301))
    ->name('privacy-policy');
Route::view('pricing', 'pricing')->middleware(MarketingLocale::class)->name('pricing');

// One landing page per repeat-purchase category. The slug is constrained to
// the configured set so an unknown one 404s in the router, and the page never
// renders empty.
// One page per supported shop, plus the hub. Both carry the same locale
// middleware as the other marketing pages, so `?lang=nl` works everywhere.
Route::get('shops', [ShopPageController::class, 'index'])
    ->middleware(MarketingLocale::class)
    ->name('shops');

Route::get('shops/{slug}', [ShopPageController::class, 'show'])
    ->where('slug', ShopPages::slugPattern())
    ->middleware(MarketingLocale::class)
    ->name('shop');

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

Route::get('.well-known/openai-apps-challenge', OpenaiAppsChallengeController::class)
    ->withoutMiddleware([
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('openai-apps-challenge');

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

// Public on purpose: the marketing pages point Pro here, and the controller
// decides between registration, checkout and the billing page. Guarding it
// with `auth` would only redirect a stranger to login and lose the intent.
Route::get('upgrade', [BillingController::class, 'upgrade'])->name('upgrade');

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
| The user-facing app. Every route is registered here in one place, and the
| middleware matches the Filament panel's authMiddleware exactly, so the access
| rules did not change when this replaced it.
|
*/
Route::prefix('app')
    ->name('app.')
    ->middleware(['auth', EnsureEmailIsVerified::class])
    ->group(function (): void {
        Route::livewire('/', Dashboard::class)->name('dashboard');
        Route::livewire('products', ProductList::class)->name('products.index');
        Route::livewire('products/create', CreateProductFromUrl::class)->name('products.create');
        Route::livewire('products/create-manual', CreateProductManual::class)->name('products.create-manual');
        Route::livewire('products/{product}', ProductShow::class)->name('products.show');
        Route::livewire('products/{product}/edit', EditProduct::class)->name('products.edit');
        Route::livewire('billing', BillingPage::class)->name('billing');
        Route::livewire('notifications', NotificationPreferences::class)->name('notifications');
        Route::livewire('connections', ConnectionsPage::class)->name('connections');
    });

require __DIR__ . '/settings.php';
