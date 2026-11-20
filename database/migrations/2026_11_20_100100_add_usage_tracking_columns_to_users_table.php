<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The opt-out from linking page views to the account, and where a person
 * came from before signing up: the referrer host and UTM values of the
 * first page load that had any, copied once at registration.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'usage_tracking_opted_out')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('usage_tracking_opted_out')->default(false);
            });
        }

        foreach (['signup_referrer_host' => 120, 'signup_utm_source' => 100, 'signup_utm_medium' => 100, 'signup_utm_campaign' => 100] as $column => $length) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column, $length): void {
                    $table->string($column, $length)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['usage_tracking_opted_out', 'signup_referrer_host', 'signup_utm_source', 'signup_utm_medium', 'signup_utm_campaign']);
        });
    }
};
