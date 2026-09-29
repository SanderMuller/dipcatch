<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The getting-started checklist item Jev placed the product under, next to
 * its category. Written on every automatic categorisation, null otherwise.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'tracking_idea')) {
            Schema::table('products', fn (Blueprint $table) => $table->string('tracking_idea')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'tracking_idea')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('tracking_idea'));
        }
    }
};
