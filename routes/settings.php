<?php declare(strict_types=1);

use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');
});

Route::middleware(['auth', EnsureEmailIsVerified::class])->group(function (): void {
    Route::livewire('settings/appearance', Appearance::class)->name('appearance.edit');

    Route::livewire('settings/security', Security::class)
        ->middleware(
            when(
                (Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'))
                || (Features::canManagePasskeys()
                    && Features::optionEnabled(Features::passkeys(), 'confirmPassword')),
                ['password.confirm'],
                [],
            ),
        )
        ->name('security.edit');
});

if (Features::canManagePasskeys()) {
    Route::get('.well-known/passkey-endpoints', function (): JsonResponse {
        return response()->json([
            'enroll' => route('security.edit'),
            'manage' => route('security.edit'),
        ]);
    })->name('well-known.passkeys');
}
