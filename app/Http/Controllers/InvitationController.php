<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Invitation;
use App\Models\User;
use App\Support\LocaleCurrency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use SanderMuller\FluentValidation\FluentRule;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InvitationController extends Controller
{
    public function show(#[SensitiveParameter]
        string $token): View
    {
        $invitation = $this->resolveInvitation($token);

        return view('auth.invitation', [
            'invitation' => $invitation,
        ]);
    }

    public function redeem(Request $request, #[SensitiveParameter]
        string $token, CreateNewUser $createNewUser): RedirectResponse
    {
        $this->resolveInvitation($token);

        $validated = $request->validate([
            'name' => FluentRule::string()->required()->max(255),
            'password' => FluentRule::string()->required()->confirmed()->min(8),
        ]);

        /** @var array{name: string, password: string} $validated */
        $acceptLanguage = $request->header('Accept-Language');
        $acceptLanguage = is_array($acceptLanguage) ? ($acceptLanguage[0] ?? null) : $acceptLanguage;

        $user = DB::transaction(function () use ($token, $validated, $createNewUser, $acceptLanguage): User {
            // Re-fetch with a row lock so concurrent redemptions of the same
            // token serialize and only the first one succeeds.
            $invitation = Invitation::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->isRedeemed() || $invitation->isExpired()) {
                throw new NotFoundHttpException('This invitation is no longer valid.');
            }

            $user = $createNewUser->create([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => $validated['password'],
                // The `confirmed` rule already proved equality;
                // reuse the validated value so CreateNewUser's validator is satisfied.
                'password_confirmation' => $validated['password'],
            ]);

            $user->forceFill([
                'default_currency' => LocaleCurrency::guess($acceptLanguage),
                'email_verified_at' => now(),
            ])->save();

            $invitation->forceFill(['redeemed_at' => now()])->save();

            return $user;
        });

        Auth::login($user);

        return redirect('/app');
    }

    private function resolveInvitation(#[SensitiveParameter]
        string $token): Invitation
    {
        $invitation = Invitation::query()->where('token', $token)->first();

        if ($invitation === null || $invitation->isRedeemed() || $invitation->isExpired()) {
            throw new NotFoundHttpException('This invitation is no longer valid.');
        }

        return $invitation;
    }
}
