<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * One row per (provider, account) pair a user signed in with.
     *
     * `provider_id` is the provider's stable subject claim, not the e-mail:
     * a user can change the address on their Google account, and Apple hands
     * out a per-app relay address that says nothing about identity.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_id');
            $table->timestamps();

            // The lookup the callback runs on every sign-in, and the guard
            // against two users claiming the same provider account.
            $table->unique(['provider', 'provider_id']);

            // A user connects each provider at most once.
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
