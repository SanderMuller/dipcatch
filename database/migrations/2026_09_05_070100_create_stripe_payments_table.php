<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stripe_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Stripe invoice or charge id. Unique per kind so a replayed
            // webhook cannot record the same money twice.
            $table->string('stripe_id')->index();
            $table->string('kind');
            $table->integer('amount');
            $table->string('currency', 3);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['stripe_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_payments');
    }
};
