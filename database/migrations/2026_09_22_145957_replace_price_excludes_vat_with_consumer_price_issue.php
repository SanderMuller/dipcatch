<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One column for every reason a stored number is not the shopper's price,
 * rather than a boolean per reason. The VAT flag shipped hours earlier; the
 * trade-only gate is the second reason and would have been the second boolean.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'consumer_price_issue')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->string('consumer_price_issue')->nullable();
            });
        }

        if (! Schema::hasColumn('shops', 'consumer_price_note')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->string('consumer_price_note')->nullable();
            });
        }

        if (Schema::hasColumn('shops', 'price_excludes_vat')) {
            DB::table('shops')
                ->where('price_excludes_vat', true)
                ->update(['consumer_price_issue' => 'excludes_vat']);
        }

        if (Schema::hasColumn('shops', 'vat_note')) {
            DB::table('shops')
                ->whereNotNull('vat_note')
                ->update(['consumer_price_note' => DB::raw('vat_note')]);

            Schema::table('shops', function (Blueprint $table): void {
                $table->dropColumn(['price_excludes_vat', 'vat_note']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shops', 'price_excludes_vat')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->boolean('price_excludes_vat')->default(false);
                $table->string('vat_note')->nullable();
            });
        }

        DB::table('shops')
            ->where('consumer_price_issue', 'excludes_vat')
            ->update(['price_excludes_vat' => true, 'vat_note' => DB::raw('consumer_price_note')]);

        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['consumer_price_issue', 'consumer_price_note']);
        });
    }
};
