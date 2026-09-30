<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('failed_shop_pages')) {
            return;
        }

        Schema::create('failed_shop_pages', function (Blueprint $table): void {
            // A shop page someone tried to add and DipCatch could not read:
            // one row per page, so we can see which shops to make work.
            $table->id();
            $table->text('url');
            $table->string('url_hash', 64)->unique();
            $table->string('host')->index();
            $table->string('failure');
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('times')->default(1);
            $table->timestamp('first_failed_at');
            $table->timestamp('last_failed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_shop_pages');
    }
};
