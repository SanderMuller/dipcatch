<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebDiscovery;
use App\Models\WebSearch;
use App\Models\WebShopFinding;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;

#[Signature('dipcatch:prune-web-searches')]
#[Description('Delete stored web searches past their reuse age that no discovery or finding references.')]
final class PruneWebSearchesCommand extends Command
{
    /**
     * One statement. The three references are `nullOnDelete`, so a row
     * deleted from under one would drop discovery's fallback search without
     * an error; a referenced row stays however old it is.
     */
    public function handle(): int
    {
        $searches = new WebSearch()->getTable();
        $discoveries = new WebDiscovery()->getTable();
        $findings = new WebShopFinding()->getTable();

        $deleted = WebSearch::query()
            ->where('searched_at', '<', now()->subDays(Config::integer('dipcatch.web_discovery.search_max_age_days') + 1))
            ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from($discoveries)->whereColumn("{$discoveries}.web_search_id", "{$searches}.id"))
            ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from($discoveries)->whereColumn("{$discoveries}.klarna_search_id", "{$searches}.id"))
            ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from($findings)->whereColumn("{$findings}.web_search_id", "{$searches}.id"))
            ->delete();

        $this->info('Pruned ' . (is_int($deleted) ? $deleted : 0) . ' web searches.');

        return self::SUCCESS;
    }
}
