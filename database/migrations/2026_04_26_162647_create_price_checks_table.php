<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('price_checks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('raw')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestampTz('checked_at');

            $table->index(['product_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_checks');
    }
};
