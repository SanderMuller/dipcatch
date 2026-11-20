<?php declare(strict_types=1);

namespace App\Analytics\Listeners;

use App\Analytics\Contracts\TrackableUser;
use App\Analytics\SignupSource;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;

/**
 * Moves the first-touch source the session carried to the new account, once.
 * Nothing else from before the signup is linked to the account.
 */
final readonly class StoreSignupSource
{
    public function __construct(private Request $request) {}

    public function handle(Registered $event): void
    {
        if (! $event->user instanceof TrackableUser || ! $this->request->hasSession()) {
            return;
        }

        $key = config()->string('analytics.signup_source_session_key', 'signup_source');
        $stored = $this->request->session()->pull($key);

        if (! is_array($stored)) {
            return;
        }

        $source = SignupSource::fromArray($stored);

        if (! $source->isEmpty()) {
            $event->user->recordSignupSource($source);
        }
    }
}
