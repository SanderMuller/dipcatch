<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // IANA timezone name (e.g. 'Europe/Amsterdam'). Drives when the
            // daily 09:00-local digest fires for this user.
            $table->string('timezone', 64)->default('Europe/Amsterdam')->after('notify_via_push');

            // When the most recent digest succeeded. Null = never sent; the
            // dispatch query coalesces null to (now - 24h) for the first send.
            $table->timestampTz('last_digest_sent_at')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'timezone',
                'last_digest_sent_at',
            ]);
        });
    }
};
