<?php declare(strict_types=1);

namespace App\Livewire\Settings\TwoFactor;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;
use TypeError;
use UnexpectedValueException;

class RecoveryCodes extends Component
{
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        $generateNewRecoveryCodes($user);

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = Auth::user();
        assert($user instanceof User);

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $recoveryCodes = $user->recoveryCodes();

                if (! is_array($recoveryCodes)) {
                    throw new UnexpectedValueException('The recovery codes must be an array.');
                }

                $this->recoveryCodes = $recoveryCodes;
            } catch (Exception|TypeError) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}
