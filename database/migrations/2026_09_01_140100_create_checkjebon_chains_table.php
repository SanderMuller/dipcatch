<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkjebon_chains', function (Blueprint $table): void {
            // Per-chain metadata from the daily dataset: the base URL a
            // product link is appended to, and the chain's display name.
            $table->id();
            $table->string('chain')->unique();
            $table->string('label');
            $table->text('base_url');
            $table->timestamp('refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkjebon_chains');
    }
};
