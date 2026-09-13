<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('shops', 'repointed_at')) {
            return;
        }

        Schema::table('shops', function (Blueprint $table): void {
            // When this offer was last pointed at a different URL. The prices
            // it reported before that belong to another product, so drop
            // detection stops reading its earlier cheapest-history segments.
            // Null means the offer still points where it was created.
            $table->timestamp('repointed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('repointed_at');
        });
    }
};
