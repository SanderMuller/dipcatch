<?php declare(strict_types=1);

namespace App\Support\DatabaseCopy;

final readonly class CopyPlan
{
    /**
     * @param  list<string>  $order  tables in dependency order
     * @param  array<string, list<string>>  $deferred  table => columns inserted as null, backfilled after all tables
     */
    public function __construct(
        public array $order,
        public array $deferred,
    ) {}
}
