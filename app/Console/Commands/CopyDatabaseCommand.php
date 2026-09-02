<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DatabaseCopy\CopyPlan;
use App\Support\DatabaseCopy\CopyPlanner;
use App\Support\DatabaseCopy\TableCopier;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * One-off engine migration: copies every application table from one
 * configured connection to another. The target must already be migrated
 * (`migrate --database=<target>`). Runtime tables (cache, sessions, queue,
 * migrations bookkeeping) are not copied — they rebuild themselves.
 */
#[AsCommand(name: 'dipcatch:copy-database', description: 'Copy all application tables from one database connection to another (engine migration).')]
final class CopyDatabaseCommand extends Command implements Isolatable
{
    protected $signature = 'dipcatch:copy-database
        {--from=mysql : Source connection name}
        {--to=pgsql_migration : Target connection name}
        {--chunk=500 : Rows per insert}
        {--truncate : Empty target tables first (reverse dependency order)}
        {--dry-run : Resolve the table order and row counts without writing}';

    public function handle(): int
    {
        $fromName = $this->stringOption('from');
        $toName = $this->stringOption('to');

        if ($fromName === $toName) {
            $this->components->error('Source and target connections are the same.');

            return self::FAILURE;
        }

        $from = DB::connection($fromName);
        $to = DB::connection($toName);
        $this->components->info(sprintf('%s (%s) → %s (%s)', $fromName, $from->getDriverName(), $toName, $to->getDriverName()));

        $plan = new CopyPlanner(Schema::connection($fromName), Schema::connection($toName))->plan();
        $this->describe($plan, $from, $to);

        if ((bool) $this->option('dry-run')) {
            $this->components->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $truncate = (bool) $this->option('truncate');

        if (! $truncate && ! $this->targetIsEmpty($to, $plan)) {
            return self::FAILURE;
        }

        $copier = new TableCopier($from, $to, Schema::connection($fromName), Schema::connection($toName), max(1, (int) $this->option('chunk')));

        // Truncation, inserts and backfill share one transaction: a failure
        // anywhere leaves the target exactly as it was.
        try {
            $this->copyInTransaction($to, $copier, $plan, $truncate);
        } catch (Throwable $e) {
            $this->components->error('Copy failed and was rolled back; the target is unchanged. ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($to->getDriverName() === 'pgsql') {
            $copier->resetPostgresSequences($plan->order);
            $this->components->info('PostgreSQL sequences reset past the copied ids.');
        }

        return $this->verify($plan, $from, $to);
    }

    private function copyInTransaction(Connection $to, TableCopier $copier, CopyPlan $plan, bool $truncate): void
    {
        $to->transaction(function () use ($to, $copier, $plan, $truncate): void {
            if ($truncate) {
                foreach (array_reverse($plan->order) as $table) {
                    $to->table($table)->delete();
                }
                $this->components->info('Target tables emptied.');
            }

            foreach ($plan->order as $table) {
                $copied = $copier->copy($table, $plan->deferred[$table] ?? []);
                $this->components->twoColumnDetail($table, number_format($copied) . ' rows');
            }

            foreach ($plan->deferred as $table => $columns) {
                $copier->backfill($table, $columns);
                $this->components->twoColumnDetail($table . ' (backfill)', implode(', ', $columns));
            }
        });
    }

    private function describe(CopyPlan $plan, Connection $from, Connection $to): void
    {
        $rows = [];
        foreach ($plan->order as $table) {
            $rows[] = [$table, number_format($from->table($table)->count()), number_format($to->table($table)->count())];
        }
        $this->table(['Table (dependency order)', 'Source rows', 'Target rows'], $rows);

        foreach ($plan->deferred as $table => $columns) {
            $this->components->info(sprintf('%s.%s inserted as null first and backfilled after all tables (foreign-key cycle).', $table, implode(', ', $columns)));
        }
    }

    private function targetIsEmpty(Connection $to, CopyPlan $plan): bool
    {
        foreach ($plan->order as $table) {
            if ($to->table($table)->exists()) {
                $this->components->error("Target table {$table} is not empty; pass --truncate to replace its rows.");

                return false;
            }
        }

        return true;
    }

    private function verify(CopyPlan $plan, Connection $from, Connection $to): int
    {
        $mismatches = [];
        foreach ($plan->order as $table) {
            $source = $from->table($table)->count();
            $target = $to->table($table)->count();
            if ($source !== $target) {
                $mismatches[] = "{$table}: source {$source}, target {$target}";
            }
        }

        if ($mismatches !== []) {
            $this->components->error('Row counts differ after copy:');
            $this->components->bulletList($mismatches);

            return self::FAILURE;
        }

        $this->components->info(sprintf('Copied %d tables; row counts match.', count($plan->order)));

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : throw new RuntimeException("Option --{$name} must be a connection name.");
    }
}
