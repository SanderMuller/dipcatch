<?php declare(strict_types=1);

use App\Http\Controllers\AutoDetectTimezoneController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PublicProductController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Middleware\MarketingLocale;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Route;

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
