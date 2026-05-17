<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // When the user's timezone was auto-detected from the browser
            // (or explicitly saved on the NotificationSettings page).
            // Null = never set; the AutoDetectTimezoneController atomically
            // populates timezone + this stamp only when it's still null,
            // so an explicit save can never be clobbered by a concurrent
            // detection POST.
            $table->timestampTz('timezone_detected_at')->nullable()->after('last_digest_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('timezone_detected_at');
        });
    }
};
