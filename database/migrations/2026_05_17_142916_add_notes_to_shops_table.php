<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            // Free-text per-shop annotation: shipping limits, coupons,
            // payment quirks. Owned by the product owner; nullable; no
            // app-level length cap (text column affords ~64KB).
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
