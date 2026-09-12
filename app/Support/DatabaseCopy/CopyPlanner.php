<?php declare(strict_types=1);

namespace App\Support\DatabaseCopy;

use Illuminate\Database\Schema\Builder as SchemaBuilder;
use RuntimeException;

/**
 * Orders tables so every table comes after the tables its foreign keys point
 * at. A cycle (products.cheapest_shop_id ↔ shops.product_id) is broken on a
 * nullable foreign key: that column is inserted as null and backfilled later.
 */
final readonly class CopyPlanner
{
    /**
     * Tables that rebuild themselves and must not be copied.
     *
     * @var list<string>
     */
    public const array SKIP = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function __construct(
        private SchemaBuilder $fromSchema,
        private SchemaBuilder $toSchema,
    ) {}

    public function plan(): CopyPlan
    {
        $names = $this->tableNames();
        $edges = [];
        $nullable = [];

        foreach ($names as $name) {
            $edges[$name] = $this->foreignEdges($name, $names);
            $nullable[$name] = $this->nullableColumns($name);
        }

        $order = [];
        $deferred = [];
        $remaining = $names;

        while ($remaining !== []) {
            $ready = array_values(array_filter(
                $remaining,
                static fn (string $table): bool => array_diff(array_keys($edges[$table]), $order) === [],
            ));

            if ($ready !== []) {
                array_push($order, ...$ready);
                $remaining = array_values(array_diff($remaining, $ready));

                continue;
            }

            [$table, $foreignTable, $columns] = $this->breakableEdge($remaining, $edges, $nullable, $order);
            unset($edges[$table][$foreignTable]);
            $deferred[$table] = array_values(array_unique([...($deferred[$table] ?? []), ...$columns]));
        }

        return new CopyPlan($order, $deferred);
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $names = [];
        foreach ($this->fromSchema->getTableListing(schemaQualified: false) as $name) {
            if (in_array($name, self::SKIP, true)) {
                continue;
            }

            if (! $this->toSchema->hasTable($name)) {
                throw new RuntimeException("Table {$name} exists on the source but not on the target — run migrations on the target first.");
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, list<string>> foreign table => referencing columns
     */
    private function foreignEdges(string $table, array $names): array
    {
        $edges = [];
        foreach ($this->toSchema->getForeignKeys($table) as $foreignKey) {
            $foreignTable = $foreignKey['foreign_table'];
            if (! is_string($foreignTable) || $foreignTable === $table || ! in_array($foreignTable, $names, true)) {
                continue;
            }

            $edges[$foreignTable] = array_values(array_filter($foreignKey['columns'], is_string(...)));
        }

        return $edges;
    }

    /**
     * @return list<string>
     */
    private function nullableColumns(string $table): array
    {
        $columns = [];
        foreach ($this->toSchema->getColumns($table) as $column) {
            if ($column['nullable'] === true && is_string($column['name'])) {
                $columns[] = $column['name'];
            }
        }

        return $columns;
    }

    /**
     * Every remaining table waits on another remaining table: pick an edge
     * whose columns are all nullable so it can be deferred.
     *
     * @param  list<string>  $remaining
     * @param  array<string, array<string, list<string>>>  $edges
     * @param  array<string, list<string>>  $nullable
     * @param  list<string>  $order
     * @return array{string, string, list<string>}
     */
    private function breakableEdge(array $remaining, array $edges, array $nullable, array $order): array
    {
        // Prefer an edge that closes a direct two-table cycle, so tables that
        // merely wait on the cycle are not deferred needlessly.
        foreach ([true, false] as $mutualOnly) {
            foreach ($remaining as $table) {
                foreach ($edges[$table] as $foreignTable => $columns) {
                    if (in_array($foreignTable, $order, true) || $columns === [] || array_diff($columns, $nullable[$table]) !== []) {
                        continue;
                    }

                    if ($mutualOnly && ! array_key_exists($table, $edges[$foreignTable] ?? [])) {
                        continue;
                    }

                    return [$table, $foreignTable, $columns];
                }
            }
        }

        throw new RuntimeException('Circular foreign keys without a nullable column to defer: ' . implode(', ', $remaining));
    }
}
