<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The barcode of a catalogue row, where the source states one (bol.com
 * does, checkjebon does not), stored with leading zeros stripped so an
 * EAN-13 and its UPC-12 compare equal.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('checkjebon_prices', 'ean')) {
            Schema::table('checkjebon_prices', fn (Blueprint $table) => $table->string('ean', 14)->nullable());
        }

        if (! Schema::hasIndex('checkjebon_prices', 'checkjebon_prices_ean_index')) {
            Schema::table('checkjebon_prices', fn (Blueprint $table) => $table->index('ean'));
        }
    }

    public function down(): void
    {
        Schema::table('checkjebon_prices', function (Blueprint $table): void {
            $table->dropIndex('checkjebon_prices_ean_index');
            $table->dropColumn('ean');
        });
    }
};
