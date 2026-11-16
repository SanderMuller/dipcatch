<?php declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Date;
use Laravel\Fortify\Features;

/**
 * The route's `password.confirm` runs on the page request only. Livewire
 * update requests skip it, and a snapshot from another account still passes
 * its checksum, so a session that never confirmed, or an open page left past
 * the timeout, could still change these credentials.
 */
trait ConfirmsPasswordAgain
{
    private function redirectedToConfirmPassword(string $feature): bool
    {
        if (! Features::optionEnabled($feature, 'confirmPassword')) {
            return false;
        }

        $confirmedAt = session('auth.password_confirmed_at');
        $confirmedAt = is_numeric($confirmedAt) ? (int) $confirmedAt : 0;

        if (Date::now()->unix() - $confirmedAt <= config()->integer('auth.password_timeout', 10800)) {
            return false;
        }

        redirect()->setIntendedUrl(route('security.edit'));
        $this->redirectRoute('password.confirm');

        return true;
    }
}
