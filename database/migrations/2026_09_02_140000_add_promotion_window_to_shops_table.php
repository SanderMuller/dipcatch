<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            // How long the shop says its current price runs for. The price
            // itself is already tracked; these columns only say until when,
            // so a low number reads as a promotion rather than the new
            // normal. Null when the shop states no window.
            $table->timestamp('promotion_starts_at')->nullable();
            $table->timestamp('promotion_ends_at')->nullable();
            $table->string('promotion_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['promotion_starts_at', 'promotion_ends_at', 'promotion_label']);
        });
    }
};
