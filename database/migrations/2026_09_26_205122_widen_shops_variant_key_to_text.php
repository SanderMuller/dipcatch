<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A variant key comes from the shop's own page — a SKU, or an offer URL — so
 * it has no length a column of 255 characters can promise. On Postgres,
 * varchar to text rewrites nothing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->text('variant_key')->nullable()->change();
        });
    }

    /**
     * Left as text: narrowing it again fails, or cuts keys, once a longer one
     * is stored.
     */
    public function down(): void {}
};
