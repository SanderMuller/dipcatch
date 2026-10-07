<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'ai_offer_shown_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('ai_offer_shown_at')->nullable();
            });
        }

        // Place => ISO 8601 time the user said "Not now" there.
        if (! Schema::hasColumn('users', 'ai_prompt_dismissals')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->json('ai_prompt_dismissals')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['ai_offer_shown_at', 'ai_prompt_dismissals']);
        });
    }
};
