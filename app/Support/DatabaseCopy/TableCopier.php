<?php declare(strict_types=1);

namespace App\Support\DatabaseCopy;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Collection;

/**
 * Row-level copy of one table between connections: casts to the target's
 * boolean columns, nulls deferred foreign keys, backfills them afterwards,
 * and moves PostgreSQL sequences past the copied ids.
 */
final readonly class TableCopier
{
    public function __construct(
        private Connection $from,
        private Connection $to,
        private SchemaBuilder $fromSchema,
        private SchemaBuilder $toSchema,
        private int $chunk,
    ) {}

    /**
     * @param  list<string>  $deferredColumns
     */
    public function copy(string $table, array $deferredColumns): int
    {
        $targetColumns = [];
        $booleanColumns = [];
        foreach ($this->toSchema->getColumns($table) as $column) {
            if (! is_string($column['name'])) {
                continue;
            }

            $targetColumns[] = $column['name'];
            if (in_array($column['type_name'], ['bool', 'boolean'], true)) {
                $booleanColumns[] = $column['name'];
            }
        }

        $copied = 0;

        $this->from->table($table)->orderBy($this->orderColumn($table))->chunk($this->chunk, function (Collection $rows) use ($table, $targetColumns, $booleanColumns, $deferredColumns, &$copied): void {
            $batch = [];
            foreach ($rows as $row) {
                $batch[] = $this->castRow((array) $row, $targetColumns, $booleanColumns, $deferredColumns);
            }

            $this->to->table($table)->insert($batch);
            $copied += count($batch);
        });

        return $copied;
    }

    /**
     * Second pass for columns that were inserted as null to break a
     * foreign-key cycle: copy their source values row by row on the primary key.
     *
     * @param  list<string>  $columns
     */
    public function backfill(string $table, array $columns): void
    {
        $key = $this->orderColumn($table);

        $this->from->table($table)
            ->select([$key, ...$columns])
            ->whereNotNull($columns[0])
            ->orderBy($key)
            ->chunk($this->chunk, function (Collection $rows) use ($table, $key, $columns): void {
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $this->to->table($table)->where($key, $row[$key])->update(array_intersect_key($row, array_flip($columns)));
                }
            });
    }

    /**
     * Auto-increment tables keep their ids on copy, so PostgreSQL's sequences
     * must be moved past the highest copied id or the next insert collides.
     *
     * @param  list<string>  $tables
     */
    public function resetPostgresSequences(array $tables): void
    {
        $grammar = $this->to->getQueryGrammar();

        foreach ($tables as $table) {
            foreach ($this->toSchema->getColumns($table) as $column) {
                if ($column['auto_increment'] !== true || ! is_string($column['name'])) {
                    continue;
                }

                $this->to->statement(sprintf(
                    "SELECT setval(pg_get_serial_sequence('%s', '%s'), COALESCE((SELECT MAX(%s) FROM %s), 0) + 1, false)",
                    $grammar->wrapTable($table),
                    $column['name'],
                    $grammar->wrap($column['name']),
                    $grammar->wrapTable($table),
                ));
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @param  list<string>  $targetColumns
     * @param  list<string>  $booleanColumns
     * @param  list<string>  $deferredColumns
     * @return array<string, mixed>
     */
    private function castRow(array $row, array $targetColumns, array $booleanColumns, array $deferredColumns): array
    {
        $values = [];
        foreach ($row as $column => $value) {
            if (! is_string($column) || ! in_array($column, $targetColumns, true)) {
                continue;
            }

            if (in_array($column, $deferredColumns, true)) {
                $values[$column] = null;
            } elseif (in_array($column, $booleanColumns, true) && $value !== null) {
                $values[$column] = (bool) $value;
            } else {
                $values[$column] = $value;
            }
        }

        return $values;
    }

    /**
     * A stable order for chunking: the primary key when the table has one,
     * else the first column.
     */
    private function orderColumn(string $table): string
    {
        foreach ($this->fromSchema->getIndexes($table) as $index) {
            if ($index['primary'] === true && isset($index['columns'][0]) && is_string($index['columns'][0])) {
                return $index['columns'][0];
            }
        }

        return $this->fromSchema->getColumnListing($table)[0] ?? 'id';
    }
}
