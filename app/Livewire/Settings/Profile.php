<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profile settings')]
final class Profile extends Component
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public const int VERIFICATION_RESENDS_PER_HOUR = 5;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        $this->name = $user->name;
        $this->email = $user->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        // Compared without case: the model stores the address lower-case, and
        // the unique rule compares byte for byte. An unchanged address keeps
        // its stored value and is not written back, so a row the lower-case
        // backfill had to skip can still save its name.
        $emailChanged = Str::lower($this->email) !== Str::lower($user->email);
        $this->email = $emailChanged ? Str::lower($this->email) : $user->email;

        // A new address must ask for the password. An address change leaves
        // the account unverified, and a reset or social login to an
        // unverified address claims the account and wipes its two-factor and
        // passkeys. Without this, a stolen session is enough to take it over.
        $rules = $this->profileRules($user->id);

        if ($emailChanged) {
            $rules['current_password'] = $this->currentPasswordRules();
        }

        try {
            /** @var array<string, mixed> $validated */
            $validated = $this->validate($rules);
        } finally {
            $this->reset('current_password');
        }

        $user->fill(array_diff_key($validated, $emailChanged ? ['current_password' => true] : ['email' => true]));

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: '/app');

            return;
        }

        $sent = RateLimiter::attempt(
            'verification-resend:' . $user->id,
            self::VERIFICATION_RESENDS_PER_HOUR,
            static fn () => $user->sendEmailVerificationNotification(),
            3600,
        );

        if ($sent === false) {
            Flux::toast(variant: 'danger', text: __('You asked for several links recently. Please wait before you ask again.'));

            return;
        }

        Flux::toast(text: __('We sent a new link to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
}
