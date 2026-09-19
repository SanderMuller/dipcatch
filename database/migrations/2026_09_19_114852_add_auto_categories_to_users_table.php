<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'auto_categories')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            // The opt-in for automatic categories. Stored for every account
            // and honoured only while the plan allows it.
            $table->boolean('auto_categories')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'auto_categories')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('auto_categories');
        });
    }
};
