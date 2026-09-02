<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration {
    /**
     * Shop suggestions match `lower(name) like '%token%'` across the whole
     * checkjebon dataset (100k+ rows). A trigram GIN index turns that
     * sequential scan into an index lookup on PostgreSQL; other engines have
     * no equivalent and keep the scan.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            // Managed Postgres without extension privileges: the query keeps
            // working, only slower. Do not fail the deploy over an index.
            Log::warning('pg_trgm unavailable; checkjebon_prices name index skipped.', ['error' => $e->getMessage()]);

            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS checkjebon_prices_name_trgm_idx ON checkjebon_prices USING gin (lower(name) gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS checkjebon_prices_name_trgm_idx');
    }
};
