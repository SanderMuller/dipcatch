<?php declare(strict_types=1);

use App\Http\Controllers\AutoDetectTimezoneController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PublicProductController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Middleware\MarketingLocale;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
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

// Public product share page — no auth, throttled per IP, exact 32-char
// alphanumeric slug. Lives outside the auth+verified group so guests
// can hit it.
Route::get('p/{slug}', PublicProductController::class)
    ->where('slug', '[A-Za-z0-9]{32}')
    ->middleware(ThrottleRequestsWithRedis::using('public-product'))
    ->name('product.public');

Route::middleware(['auth', EnsureEmailIsVerified::class])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');

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

require __DIR__ . '/settings.php';
