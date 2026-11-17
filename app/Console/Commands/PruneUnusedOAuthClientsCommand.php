<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Anyone may register a client for the MCP server, so without this the
 * table keeps every registration that never led to a sign-in. Only public
 * clients without an owner are deleted: what registration creates.
 */
#[Signature('dipcatch:prune-unused-oauth-clients')]
#[Description('Delete self-registered OAuth clients that no account ever signed in with.')]
final class PruneUnusedOAuthClientsCommand extends Command
{
    /** Time to finish the first sign-in after registering. */
    public const int KEEP_DAYS = 7;

    public function handle(): int
    {
        $deleted = DB::table('oauth_clients')
            ->whereNull('owner_id')
            ->whereNull('secret')
            ->where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from('oauth_access_tokens')->whereColumn('oauth_access_tokens.client_id', 'oauth_clients.id'))
            ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from('oauth_auth_codes')->whereColumn('oauth_auth_codes.client_id', 'oauth_clients.id'))
            ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from('oauth_device_codes')->whereColumn('oauth_device_codes.client_id', 'oauth_clients.id'))
            ->delete();

        $this->info("Pruned {$deleted} unused OAuth clients.");

        return self::SUCCESS;
    }
}
