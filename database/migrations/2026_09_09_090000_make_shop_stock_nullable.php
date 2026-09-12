<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            // Three answers, not two: null means the shop's page did not say
            // whether the product can be bought. A NOT NULL column forced
            // every unreadable page to claim "in stock", which is how a
            // sold-out product was reported as available. `price_checks`
            // has recorded stock this way since it was created.
            $table->boolean('current_in_stock')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Rows recorded as unknown have no boolean to go back to. The column
        // used to default to true, so that is what they were before.
        DB::table('shops')->whereNull('current_in_stock')->update(['current_in_stock' => true]);

        Schema::table('shops', function (Blueprint $table): void {
            $table->boolean('current_in_stock')->default(true)->nullable(false)->change();
        });
    }
};
