<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unique visitors and users per calendar week and month, rebuilt from
 * `page_views` by `analytics:rollup`. Kept after the raw rows are pruned.
 * Holds counts only, no personal data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('page_view_period_stats')) {
            return;
        }

        Schema::create('page_view_period_stats', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 8);
            $table->date('starts_on');
            $table->string('section', 16);
            $table->unsignedInteger('visitors');
            $table->unsignedInteger('users');

            $table->unique(['period', 'starts_on', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_view_period_stats');
    }
};
