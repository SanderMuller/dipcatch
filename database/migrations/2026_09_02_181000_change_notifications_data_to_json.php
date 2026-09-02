<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Filament's database notifications filter on `data->>'format'`, which
     * PostgreSQL only allows on json columns; the stock Laravel migration
     * created `data` as text. MySQL and SQLite were lenient, so this only
     * surfaced on Postgres.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');

            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->json('data')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');

            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->text('data')->change();
        });
    }
};
