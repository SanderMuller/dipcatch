<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'price_excludes_vat')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->boolean('price_excludes_vat')->default(false);
            });
        }

        if (! Schema::hasColumn('shops', 'vat_note')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->string('vat_note')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['price_excludes_vat', 'vat_note']);
        });
    }
};
