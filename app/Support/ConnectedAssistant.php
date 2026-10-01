<?php declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Laravel\Passport\Token;

final class ConnectedAssistant
{
    /**
     * The name of an assistant that can use the DipCatch tools on this
     * account now — a live grant with the MCP scope — so a hint can say "ask
     * it" rather than "connect it". Null when none can.
     */
    public static function nameFor(User $user): ?string
    {
        $token = Token::query()
            ->with('client')
            ->where('user_id', $user->id)
            ->where('revoked', false)
            ->where(fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('client', fn (EloquentQueryBuilder $client): EloquentQueryBuilder => $client->where('revoked', false))
            ->latest('created_at')
            ->get()
            ->first(fn (Token $token): bool => $token->can('mcp:use'));

        if (! $token instanceof Token) {
            return null;
        }

        return is_string($token->client?->name) && $token->client->name !== '' ? $token->client->name : __('Your assistant');
    }
}
