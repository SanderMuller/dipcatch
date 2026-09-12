<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Set when a chargeback is lost. Blocks Pro entitlements even
            // while Stripe still reports the subscription as active.
            $table->timestamp('billing_blocked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('billing_blocked_at');
        });
    }
};
