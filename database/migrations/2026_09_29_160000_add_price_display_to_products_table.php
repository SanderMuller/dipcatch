<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The price a product leads with when the person chose one: `pack` or `unit`.
 * Null follows the rule in App\Support\HeadlinePrice.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'price_display')) {
            Schema::table('products', fn (Blueprint $table) => $table->string('price_display')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'price_display')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('price_display'));
        }
    }
};
