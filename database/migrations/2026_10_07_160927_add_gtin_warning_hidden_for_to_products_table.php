<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'gtin_warning_hidden_for')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->text('gtin_warning_hidden_for')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('gtin_warning_hidden_for');
        });
    }
};
