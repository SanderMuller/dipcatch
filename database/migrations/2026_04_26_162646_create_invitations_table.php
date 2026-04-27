<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->constrained('users');
            $table->timestampTz('redeemed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['email', 'redeemed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
