<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The opt-in for the AI check on a newly added shop, and when the person
 * waved away the in-app prompts that offer the AI features.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'shop_checks')) {
            Schema::table('users', fn (Blueprint $table) => $table->boolean('shop_checks')->default(false));
        }

        if (! Schema::hasColumn('users', 'ai_prompts_dismissed_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->timestamp('ai_prompts_dismissed_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'ai_prompts_dismissed_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('ai_prompts_dismissed_at'));
        }

        if (Schema::hasColumn('users', 'shop_checks')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('shop_checks'));
        }
    }
};
