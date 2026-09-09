<?php declare(strict_types=1);

namespace App\Livewire\Connections;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\Token;
use Livewire\Component;

/**
 * What the user has connected to their account, and how to disconnect it.
 *
 * Without this an authorisation can only ever be granted, never withdrawn,
 * which is not a reasonable thing to ask someone to live with.
 */
class ConnectionsPage extends Component
{
    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * @return list<array{id: string, name: string, granted: string|null, expires: string|null}>
     */
    public function connections(): array
    {
        $rows = [];

        foreach ($this->tokens()->get() as $token) {
            $created = $token->created_at;
            $expires = $token->expires_at;

            $rows[] = [
                'id' => is_scalar($token->getKey()) ? (string) $token->getKey() : '',
                'name' => is_string($token->client?->name) ? $token->client->name : 'An application',
                'granted' => $created instanceof CarbonInterface ? $created->toFormattedDayDateString() : null,
                'expires' => $expires instanceof CarbonInterface ? $expires->toFormattedDayDateString() : null,
            ];
        }

        return $rows;
    }

    public function endpoint(): string
    {
        return url('/mcp');
    }

    public function revoke(string $tokenId): void
    {
        $token = $this->tokens()->whereKey($tokenId)->first();

        if (! $token instanceof Token) {
            return;
        }

        // Revoking the access token alone leaves its refresh token able to
        // mint a fresh one, so the connection would come straight back.
        $token->refreshToken?->revoke();
        $token->revoke();

        $this->dispatch('disconnected');
    }

    public function render(): View
    {
        return view('livewire.connections.connections-page', [
            'connections' => $this->connections(),
            'endpoint' => $this->endpoint(),
        ]);
    }

    /**
     * @return Builder<Token>
     */
    private function tokens(): Builder
    {
        return Token::query()
            ->with('client')
            ->where('user_id', $this->user()->getKey())
            ->where('revoked', false)
            ->latest('created_at');
    }
}
