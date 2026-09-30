<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('hidden_shops')) {
            return;
        }

        Schema::create('hidden_shops', function (Blueprint $table): void {
            // A shop a person never wants suggested, on any product. Shops
            // they track there are not affected.
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->string('label');
            $table->timestamp('hidden_at');

            $table->unique(['user_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_shops');
    }
};
