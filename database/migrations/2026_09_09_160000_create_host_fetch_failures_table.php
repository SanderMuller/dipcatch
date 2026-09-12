<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('host_fetch_failures', function (Blueprint $table): void {
            // How a shop's host has answered lately, so a standing block is
            // not reported as "try again shortly".
            //
            // A table rather than the cache: the count must survive between
            // requests and across workers, and it did not. The cache store is
            // configuration, and a counter whose correctness depends on which
            // store an environment happens to use is a counter that reads
            // zero in production while every test passes.
            $table->id();
            $table->string('host', 255);
            $table->string('kind', 16);
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('last_failed_at');
            $table->timestamps();

            $table->unique(['host', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_fetch_failures');
    }
};
