<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkjebon_prices', function (Blueprint $table): void {
            // Local copy of the daily checkjebon.nl price dataset. One row
            // per (supermarket, external product id); refreshed_at marks the
            // run that last saw the row, so a refresh can prune delisted
            // products per supermarket.
            $table->id();
            $table->string('supermarket');
            $table->string('external_id');
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->string('size')->nullable();
            $table->timestamp('refreshed_at');

            $table->unique(['supermarket', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkjebon_prices');
    }
};
