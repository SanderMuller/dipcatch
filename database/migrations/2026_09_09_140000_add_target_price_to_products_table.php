<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // "Tell me when this costs less than 18 euro." The price on the
            // shelf, which is what shoppers say out loud. `unit_price_target`
            // answers the same question per kilo, and cannot stand in for
            // this one: converting needs a pack size, and plenty of shops
            // publish none.
            $table->decimal('target_price', 12, 2)->nullable();
            $table->decimal('target_price_notified', 12, 2)->nullable();
            $table->timestamp('target_price_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['target_price', 'target_price_notified', 'target_price_notified_at']);
        });
    }
};
