<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Pro granted without payment. A future timestamp means comped
            // until then; "forever" is a far-future date rather than a second
            // column, so there is no state where the two disagree.
            $table->timestamp('comped_until')->nullable();

            // Why it was given. A comp with no recorded reason becomes a
            // mystery to whoever finds it later.
            $table->string('comped_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['comped_until', 'comped_reason']);
        });
    }
};
