<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProbeFailure;
use App\Models\FailedShopPage;
use App\Support\UrlNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Str;

/**
 * The admin "Pages we could not read" list for a terminal, so
 * `cloud command:run` can read it. No person is printed: Cloud keeps command
 * output in its history.
 */
#[Signature('dipcatch:failed-shop-pages {--limit=50 : Pages to list} {--host= : Only this shop host} {--failure= : Only this failure, for example blocked}')]
#[Description('List the shop pages DipCatch could not read, newest try first. Read-only.')]
final class FailedShopPagesCommand extends Command
{
    public function handle(): int
    {
        $failureOption = $this->option('failure');
        $failure = is_string($failureOption) ? ProbeFailure::tryFrom($failureOption) : null;

        if (is_string($failureOption) && ! in_array($failure, FailedShopPage::recordable(), strict: true)) {
            $known = implode(', ', array_map(static fn (ProbeFailure $recordable): string => $recordable->value, FailedShopPage::recordable()));
            $this->error("Unknown failure \"{$failureOption}\". Use one of: {$known}");

            return self::FAILURE;
        }

        $host = $this->option('host');
        $query = FailedShopPage::query()
            ->when(is_string($host) && $host !== '', static fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('host', UrlNormalizer::normalizeHost((string) $host)))
            ->when($failure !== null, static fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->where('failure', $failure));

        $total = (clone $query)->count();
        $pages = $query->latest('last_failed_at')->limit(max(1, (int) $this->option('limit')))->get();

        $this->table(['shop', 'page', 'failure', 'reason', 'tries', 'last try'], $pages->map(static fn (FailedShopPage $page): array => [
            $page->host,
            $page->url,
            Str::headline($page->failure->value),
            (string) $page->reason,
            $page->times,
            $page->last_failed_at->toDateTimeString(),
        ])->all());

        $this->line("Showing {$pages->count()} of {$total}.");

        return self::SUCCESS;
    }
}
