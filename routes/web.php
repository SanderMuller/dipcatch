<?php declare(strict_types=1);

use App\Http\Controllers\AutoDetectTimezoneController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('push/subscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    // 30/min per authenticated user is generous for legitimate use (JS fires
    // once per page load, then stops once stamped) and bounds a tab-storm /
    // buggy client.
    Route::post('profile/timezone/auto-detect', AutoDetectTimezoneController::class)
        ->middleware('throttle:30,1')
        ->name('profile.timezone.auto-detect');
});

Route::middleware('throttle:invitation')->group(function (): void {
    Route::get('invite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invite/{token}', [InvitationController::class, 'redeem'])->name('invitation.redeem');
});

require __DIR__ . '/settings.php';
