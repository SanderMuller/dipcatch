<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_suggestion_dismissals', function (Blueprint $table): void {
            // A suggestion the user rejected for one product. Keyed on the
            // dataset row, so a refresh that replaces the row with a new
            // external id surfaces the suggestion again — the catalogue
            // changed, and the old rejection no longer describes it.
            $table->id();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('chain');
            $table->string('external_id');
            $table->timestamp('dismissed_at');

            $table->unique(['product_id', 'chain', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_suggestion_dismissals');
    }
};
