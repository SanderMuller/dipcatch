<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Unguessable public share slug. Null = not shared. Non-null =
            // accessible at /p/{slug}. Owner can revoke by setting to null
            // and rotate by setting to a fresh random string.
            $table->string('share_slug', 32)->nullable()->unique()->after('cheapest_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('share_slug');
        });
    }
};
