<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('catalogue_links')) {
            return;
        }

        Schema::create('catalogue_links', function (Blueprint $table): void {
            // Whether a supermarket list row's product page is on the shop's
            // site, so a suggestion skips a page a check found gone.
            $table->id();
            $table->string('chain', 32);
            $table->string('external_id');
            $table->boolean('alive');
            // The shop's own address for a live page, when the check found one.
            $table->text('url')->nullable();
            $table->timestamp('checked_at');

            $table->unique(['chain', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_links');
    }
};
