<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per counted page load. Raw and short-lived; the rows are pruned.
 * The table never holds an IP address, a query string or a full referrer URL.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('page_views')) {
            return;
        }

        Schema::create('page_views', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('viewed_at')->index();
            $table->string('section', 16);
            $table->string('route_name', 120)->nullable();
            $table->string('path', 255);
            $table->string('referrer_host', 120)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('device', 8);
            $table->boolean('is_authenticated');
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->cascadeOnDelete();
            $table->char('visitor_hash', 32);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
