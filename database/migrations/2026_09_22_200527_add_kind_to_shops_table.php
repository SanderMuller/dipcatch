<?php declare(strict_types=1);

use App\Enums\ShopKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop is either read on a schedule or kept as a link — see
 * {@see ShopKind}.
 *
 * Every existing row is tracked, which is what the default says, so the column
 * can be added to a live table without a backfill.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'kind')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->string('kind')->default('tracked');
            });
        }

        if (! Schema::hasColumn('shops', 'unreadable_reason')) {
            Schema::table('shops', function (Blueprint $table): void {
                // Why the page could not be read when the link was kept. Held
                // so a surface can say which wall it hit, and so the retry can
                // tell a shop worth asking again from one that never will be.
                $table->string('unreadable_reason')->nullable();
            });
        }

        if (! Schema::hasColumn('shops', 'retried_at')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->timestamp('retried_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'unreadable_reason', 'retried_at']);
        });
    }
};
