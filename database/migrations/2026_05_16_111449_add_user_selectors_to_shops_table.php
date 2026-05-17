<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('price_selector')->nullable()->after('adapter_key');
            $table->string('title_selector')->nullable()->after('price_selector');
            $table->string('image_selector')->nullable()->after('title_selector');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['price_selector', 'title_selector', 'image_selector']);
        });
    }
};
