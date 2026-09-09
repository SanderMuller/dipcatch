<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration is open to everyone, so the wait list has no job left. The
 * app no longer reads or writes this table — the model, the Livewire form,
 * the admin resource and the exporter are all gone.
 *
 * Dropping it destroys any addresses still stored. That was confirmed
 * explicitly before this migration was written; `down()` restores the shape
 * of the table, never its rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('waitlist_signups');
    }

    public function down(): void
    {
        Schema::create('waitlist_signups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestampsTz();
        });
    }
};
