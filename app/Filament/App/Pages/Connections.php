<?php declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\Token;

/**
 * What the user has connected to their account, and how to disconnect it.
 *
 * Without this an authorisation can only ever be granted, never withdrawn,
 * which is not a reasonable thing to ask someone to live with.
 */
class Connections extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $navigationLabel = 'Connections';

    protected static ?string $title = 'Connections';

    protected static ?string $slug = 'connections';

    protected string $view = 'filament.app.pages.connections';

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

        Notification::make()->success()->title('Disconnected')->send();
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
