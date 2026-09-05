<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table): void {
            $table->id();
            // Stripe's own event id. The unique index is the claim: the
            // insert that wins is the delivery that runs the side effects.
            $table->string('stripe_id')->unique();
            $table->string('type');
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
