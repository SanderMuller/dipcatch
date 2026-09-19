<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'category')) {
            Schema::table('products', function (Blueprint $table): void {
                // A leaf key of the product taxonomy, "food.coffee_tea". Null
                // means nobody and nothing has placed the product yet.
                $table->string('category')->nullable();
            });
        }

        if (! Schema::hasColumn('products', 'category_set_by')) {
            Schema::table('products', function (Blueprint $table): void {
                // "user" or "auto". Null until anyone has set or cleared the
                // category; a user who clears it leaves category null and
                // this "user", which keeps automatic categorisation away.
                $table->string('category_set_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['category', 'category_set_by'] as $column) {
            if (Schema::hasColumn('products', $column)) {
                Schema::table('products', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
