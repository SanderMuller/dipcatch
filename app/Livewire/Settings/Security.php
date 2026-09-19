<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Exception;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use UnexpectedValueException;

/**
 * @phpstan-type PasskeyListItem array{
 *     id: int,
 *     name: string,
 *     authenticator: string|null,
 *     created_at_diff: string,
 *     last_used_at_diff: string|null
 * }
 */
#[Title('Security settings')]
final class Security extends Component
{
    use PasswordValidationRules;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public bool $canManageTwoFactor;

    #[Locked]
    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    /**
     * @var list<PasskeyListItem>
     */
    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    #[Locked]
    public string $qrCodeSvg = '';

    #[Locked]
    public string $manualSetupKey = '';

    public bool $showModal = false;

    public bool $showVerificationStep = false;

    #[Validate('required|string|size:6', onUpdate: false)]
    public string $code = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            $user = Auth::user();
            assert($user instanceof User);

            if (Fortify::confirmsTwoFactorAuthentication() && is_null($user->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication($user);
            }

            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        $user = Auth::user();
        assert($user instanceof User);

        $user->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    public function loadPasskeys(): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        $this->passkeys = $user->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(function (Passkey $passkey): array {
                return [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'authenticator' => $passkey->authenticator,
                    'created_at_diff' => $passkey->created_at?->diffForHumans() ?? '',
                    'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                ];
            })
            ->values()
            ->all();
    }

    public function confirmDelete(int $passkeyId): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        $passkey = $user->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if ($this->deletingPasskeyId === null) {
            return;
        }

        $user = Auth::user();
        assert($user instanceof User);

        $passkey = $user->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey($user, $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Enable two-factor authentication for the user.
     */
    public function enable(EnableTwoFactorAuthentication $enableTwoFactorAuthentication): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        $enableTwoFactorAuthentication($user);

        $this->loadSetupData();

        $this->showModal = true;
    }

    /**
     * Load the two-factor authentication setup data for the user.
     */
    private function loadSetupData(): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        try {
            $manualSetupKey = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret);

            if (! is_string($manualSetupKey)) {
                throw new UnexpectedValueException('The two-factor secret must be a string.');
            }

            $this->qrCodeSvg = (string) $user->twoFactorQrCodeSvg();
            $this->manualSetupKey = $manualSetupKey;
        } catch (Exception) {
            $this->addError('setupData', 'Failed to fetch setup data.');

            $this->reset('qrCodeSvg', 'manualSetupKey');
        }
    }

    /**
     * Show the two-factor verification step if necessary.
     */
    public function showVerificationIfNecessary(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;

            $this->resetErrorBag();

            return;
        }

        $this->closeModal();
    }

    /**
     * Confirm two-factor authentication for the user.
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        $this->validate();

        $user = Auth::user();
        assert($user instanceof User);

        $confirmTwoFactorAuthentication($user, $this->code);

        $this->closeModal();
    }

    /**
     * Reset two-factor verification state.
     */
    public function resetVerification(): void
    {
        $this->reset('code', 'showVerificationStep');

        $this->resetErrorBag();
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        $disableTwoFactorAuthentication($user);
    }

    /**
     * Close the two-factor authentication modal.
     */
    public function closeModal(): void
    {
        $this->reset(
            'code',
            'manualSetupKey',
            'qrCodeSvg',
            'showModal',
            'showVerificationStep',
        );

        $this->resetErrorBag();
    }

    /**
     * Exact because Fortify's actions mutate the very `Auth::user()`
     * instance this reads, so a render after any of them sees the new
     * attributes. It is also memoised per request, which is safe only while
     * nothing reads it before those actions run — the first read is the
     * render that follows. A caller that read it, mutated and re-read inside
     * one request would need `unset($this->twoFactorEnabled)`.
     */
    #[Computed]
    public function twoFactorEnabled(): bool
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * Get the current modal configuration state.
     */
    #[Computed]
    public function modalConfig(): array
    {
        if ($this->twoFactorEnabled) {
            return [
                'title' => __('Two-factor authentication enabled'),
                'description' => __('Two-factor authentication is on. Open the app on your phone and scan this square, or type the setup key below.'),
                'buttonText' => __('Close'),
            ];
        }

        if ($this->showVerificationStep) {
            return [
                'title' => __('Check the code'),
                'description' => __('Type the six-digit code from the app on your phone.'),
                'buttonText' => __('Continue'),
            ];
        }

        return [
            'title' => __('Enable two-factor authentication'),
            'description' => __('Open the app on your phone and scan this square. If the app cannot scan, type the setup key below instead.'),
            'buttonText' => __('Continue'),
        ];
    }
}
