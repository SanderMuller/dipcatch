<?php declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Every place an AI prompt shows, as `ai_prompt_dismissals` keys them. */
    private const array PLACES = ['product_list', 'product_category', 'add_shop', 'wizard_shops', 'shop_suggestions', 'dashboard_suggestions', 'pack_size', 'alert'];

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'ai_prompts_dismissed_at')) {
            return;
        }

        // An account-wide "Not now" from the last 30 days still keeps the
        // prompts quiet: copy it to every place it has no newer answer for.
        DB::table('users')
            ->whereNotNull('ai_prompts_dismissed_at')
            ->where('ai_prompts_dismissed_at', '>', now()->subDays(30))
            ->orderBy('id')
            ->each(function (object $user): void {
                if (! is_string($user->ai_prompts_dismissed_at)) {
                    return;
                }

                $dismissals = is_string($user->ai_prompt_dismissals) ? (array) json_decode($user->ai_prompt_dismissals, true) : [];
                $at = CarbonImmutable::parse($user->ai_prompts_dismissed_at)->toIso8601String();

                foreach (self::PLACES as $place) {
                    $dismissals[$place] ??= $at;
                }

                DB::table('users')->where('id', $user->id)->update(['ai_prompt_dismissals' => json_encode($dismissals)]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ai_prompts_dismissed_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'ai_prompts_dismissed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('ai_prompts_dismissed_at')->nullable();
            });
        }
    }
};
