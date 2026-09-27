<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Actions\Users\SignOutEverywhere;
use App\Concerns\PasswordValidationRules;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class SignOutEverywhereForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    public bool $showConfirm = false;

    public function signOutEverywhere(SignOutEverywhere $signOutEverywhere): void
    {
        try {
            $this->validate([
                'password' => $this->currentPasswordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('password');

            throw $e;
        }

        $signOutEverywhere($this->password);

        $this->reset('password', 'showConfirm');

        Flux::toast(variant: 'success', text: __('Signed out of every other browser. If you use Claude or ChatGPT with DipCatch, connect them again.'));
    }
}
