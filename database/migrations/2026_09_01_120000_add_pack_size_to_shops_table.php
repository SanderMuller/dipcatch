<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            // Normalized pack size behind the unit price (€/kg, €/l, €/stuk).
            // Quantity is grams, milliliters, or a piece count; the unit is
            // one of 'g' | 'ml' | 'piece'. Both null until a source or a
            // title parse supplies a size.
            $table->decimal('pack_quantity', 10, 2)->nullable();
            $table->string('pack_unit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['pack_quantity', 'pack_unit']);
        });
    }
};
