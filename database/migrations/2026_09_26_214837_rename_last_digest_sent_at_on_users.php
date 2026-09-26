<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily digest now stamps this column on every run, mail or not, so it
 * records how far the digest has processed, not when one was sent.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'last_digest_sent_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->renameColumn('last_digest_sent_at', 'digest_processed_until'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'digest_processed_until')) {
            Schema::table('users', fn (Blueprint $table) => $table->renameColumn('digest_processed_until', 'last_digest_sent_at'));
        }
    }
};
