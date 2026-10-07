<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'country')) {
            Schema::table('users', function (Blueprint $table): void {
                // The country web discovery finds shops for, as a lowercase
                // ISO 3166 code. Null until the user picks one: the timezone
                // decides until then.
                $table->string('country', 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('country');
        });
    }
};
