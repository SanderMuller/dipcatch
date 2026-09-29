<?php declare(strict_types=1);

use App\Enums\ShopKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three columns describe a price that has been read: which reader produced it,
 * what it was, and when. A shop kept as a link has never been read, so the
 * honest value for all three is none — see {@see ShopKind}.
 *
 * Widening only. Every existing row keeps what it has, and the one caller that
 * passes `adapter_key` on — `AdapterResolver::resolve()` — already accepts null,
 * because a shop that never checked successfully had nothing to persist.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('adapter_key')->nullable()->change();
            $table->decimal('initial_price', 12, 2)->nullable()->change();
            $table->timestamp('initial_checked_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('adapter_key')->nullable(false)->change();
            $table->decimal('initial_price', 12, 2)->nullable(false)->change();
            $table->timestamp('initial_checked_at')->nullable(false)->change();
        });
    }
};
