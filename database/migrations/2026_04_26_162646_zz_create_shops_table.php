<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('url_hash', 64);
            $table->string('host');
            $table->string('adapter_key');
            $table->char('currency', 3);
            $table->decimal('initial_price', 12, 2);
            $table->timestampTz('initial_checked_at');
            $table->decimal('current_price', 12, 2)->nullable();
            $table->boolean('current_in_stock')->default(true);
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->string('last_status')->default('pending');
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('consecutive_5xx_failures')->default(0);
            $table->string('health')->default('ok');
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['product_id', 'url_hash']);
            $table->index(['active', 'last_checked_at']);
            $table->index('host');
        });

        // Resolve circular FK: products.cheapest_shop_id was created without
        // a constraint in the products migration so that shops could be
        // created here; add the FK now with nullOnDelete so offer deletion
        // simply clears the pointer.
        Schema::table('products', function (Blueprint $table): void {
            $table->foreign('cheapest_shop_id')
                ->references('id')
                ->on('shops')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['cheapest_shop_id']);
        });

        Schema::dropIfExists('shops');
    }
};
