<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'affiliate_links_excluded')) {
            Schema::table('users', function (Blueprint $table): void {
                // Shop links stay plain for this account: an affiliate
                // program forbids its partner's own purchases through them.
                $table->boolean('affiliate_links_excluded')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('affiliate_links_excluded');
        });
    }
};
