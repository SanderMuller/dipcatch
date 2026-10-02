<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('api_usage_days')) {
            return;
        }

        Schema::create('api_usage_days', function (Blueprint $table): void {
            // Calls to outside services (Jev, Serper, bol.com), counted per
            // day and per kind of call, for the admin dashboard.
            $table->id();
            $table->date('day');
            $table->string('service', 32);
            $table->string('purpose', 48);
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('refusals')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);

            $table->unique(['day', 'service', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_days');
    }
};
