<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The first release of the second-size check stored every Jev answer under
     * 0.8 as a rejection, and Jev rates even a right pair about 0.5 to 0.7. The
     * chance was not kept, so a true rejection cannot be told apart: forget
     * them all, and the next read asks again under the 0.2 cutoff.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'alt_pack_confirmed')) {
            return;
        }

        DB::table('shops')
            ->where('alt_pack_confirmed', false)
            ->update(['alt_pack_confirmed' => null, 'alt_pack_check_key' => null]);
    }

    public function down(): void
    {
        // The rejections it forgot were not kept.
    }
};
