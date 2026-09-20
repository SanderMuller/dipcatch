<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('products', 'last_notified_unit')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            // Which basis `last_notified_price` is money in: a unit code while
            // the product compares per unit, null while it compares packs.
            // Without it a latch armed in one basis silently suppresses drops in
            // the other — €6.15 a pack reads as €6.15 a kilo and every real fall
            // above it stays quiet.
            $table->string('last_notified_unit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('last_notified_unit');
        });
    }
};
