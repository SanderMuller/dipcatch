<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('gtin', 14)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('gtin');
        });
    }
};
