<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account's one shopping list, as two columns on the product: when it
 * went on the list, and when the user crossed it off.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'listed_at')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->timestamp('listed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('products', 'list_checked_at')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->timestamp('list_checked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['listed_at', 'list_checked_at']);
        });
    }
};
