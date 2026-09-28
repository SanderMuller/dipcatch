<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'tracking_ideas_hidden_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->timestamp('tracking_ideas_hidden_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'tracking_ideas_hidden_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('tracking_ideas_hidden_at'));
        }
    }
};
