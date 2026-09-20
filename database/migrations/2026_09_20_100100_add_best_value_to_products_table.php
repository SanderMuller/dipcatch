<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('products', 'best_value_shop_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            // The per-unit winner, beside the lowest-outlay winner the
            // `cheapest_*` columns already hold. Two answers, deliberately kept
            // apart: "what leaves my account" and "what is the better deal" are
            // different questions, and fourteen files read `cheapest_price`
            // expecting the first one.
            $table->string('best_value_shop_id')->nullable();
            $table->decimal('best_value_price', 10, 2)->nullable();
            $table->decimal('best_value_pack_quantity', 12, 2)->nullable();
            $table->string('best_value_pack_unit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['best_value_shop_id', 'best_value_price', 'best_value_pack_quantity', 'best_value_pack_unit']);
        });
    }
};
