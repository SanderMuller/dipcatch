<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily totals per section, dimension and value, rebuilt from `page_views`
 * by `analytics:rollup`. Kept after the raw rows are pruned. Holds counts
 * only, no personal data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('page_view_daily_stats')) {
            return;
        }

        Schema::create('page_view_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('section', 16);
            $table->string('dimension', 24);
            $table->string('value', 255);
            $table->unsignedInteger('views');
            $table->unsignedInteger('visitors');
            $table->unsignedInteger('users');

            $table->unique(['date', 'section', 'dimension', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_view_daily_stats');
    }
};
