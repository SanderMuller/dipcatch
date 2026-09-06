<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // The point from which this product's history is kept for good.
            // Stamped once while the owner is entitled to unlimited history
            // and never moved, so cancelling cannot retract what was kept.
            $table->timestamp('history_kept_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('history_kept_from');
        });
    }
};
