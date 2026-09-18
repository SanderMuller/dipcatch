<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('adapter_canary_results')) {
            return;
        }

        Schema::create('adapter_canary_results', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('adapter')->unique();
            // The URL the row was observed at. A repointed entry must not be
            // measured against the price of the product it replaced.
            $table->text('url');
            $table->string('outcome');
            $table->string('observed_adapter')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('last_ok_price', 12, 2)->nullable();
            $table->unsignedInteger('consecutive_unreachable')->default(0);
            $table->text('detail')->nullable();
            $table->timestampTz('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adapter_canary_results');
    }
};
