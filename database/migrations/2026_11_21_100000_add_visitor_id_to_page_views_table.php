<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A keyed hash of the guest visitor cookie, so a returning guest counts once
 * per week and month. Never the cookie value itself. Null for signed-in users
 * and for guests without the cookie.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('page_views', 'visitor_id')) {
            return;
        }

        Schema::table('page_views', function (Blueprint $table): void {
            $table->char('visitor_id', 32)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('page_views', 'visitor_id')) {
            return;
        }

        Schema::table('page_views', function (Blueprint $table): void {
            $table->dropColumn('visitor_id');
        });
    }
};
