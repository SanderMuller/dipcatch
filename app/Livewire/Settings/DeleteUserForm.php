<?php declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Actions\Users\DeleteUser;
use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout, DeleteUser $delete): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();
        assert($user instanceof User);

        // Deleted before the logout: a Stripe failure must leave the account
        // signed in with the error on screen, not signed out and still here.
        try {
            $delete($user, $user);
        } catch (Throwable $exception) {
            report($exception);

            $this->addError('password', __('Your account could not be deleted right now. Please try again in a moment.'));

            return;
        }

        $logout();

        $this->redirect('/', navigate: true);
    }
}
