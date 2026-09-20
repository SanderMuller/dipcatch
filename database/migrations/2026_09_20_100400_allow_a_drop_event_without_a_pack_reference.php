<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('price_drop_events', function (Blueprint $table): void {
            // A drop measured across two pack sizes has no pack-money
            // reference: the winner and the shops it is measured against sold
            // different amounts. Null says so. Writing the per-unit figure here
            // instead would put €12.58 a kilo behind a "Was €12.58" label.
            $table->decimal('reference_price', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->decimal('reference_price', 12, 2)->nullable(false)->change();
        });
    }
};
