<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A pack size Jev confirmed for a page that states none, apart from
        // the page's own pack columns: a check writes those, and a page that
        // says nothing must not wipe what Jev confirmed.
        if (! Schema::hasColumn('shops', 'confirmed_pack_quantity')) {
            Schema::table('shops', fn (Blueprint $table) => $table->decimal('confirmed_pack_quantity', 12, 2)->nullable());
        }

        if (! Schema::hasColumn('shops', 'confirmed_pack_unit')) {
            Schema::table('shops', fn (Blueprint $table) => $table->string('confirmed_pack_unit', 16)->nullable());
        }

        // What was asked, the page and the size, so the same question is not
        // paid for twice, whatever the answer was.
        if (! Schema::hasColumn('shops', 'pack_check_key')) {
            Schema::table('shops', fn (Blueprint $table) => $table->string('pack_check_key', 64)->nullable());
        }

        if (! Schema::hasColumn('shops', 'pack_checked_at')) {
            Schema::table('shops', fn (Blueprint $table) => $table->timestamp('pack_checked_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['confirmed_pack_quantity', 'confirmed_pack_unit', 'pack_check_key', 'pack_checked_at']);
        });
    }
};
