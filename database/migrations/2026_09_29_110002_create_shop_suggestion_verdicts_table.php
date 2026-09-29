<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('shop_suggestion_verdicts')) {
            return;
        }

        Schema::create('shop_suggestion_verdicts', function (Blueprint $table): void {
            // Jev's answer to "does this dataset row sell the same product
            // and pack?", kept so a suggestion is checked once, not on every
            // render. Keyed on the dataset row like a dismissal.
            $table->id();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('chain');
            $table->string('external_id');
            // What Jev was shown: the product title and the row's name and
            // size. A refresh or a rename that changes them voids the answer.
            $table->string('fingerprint', 64);
            $table->float('same_chance');
            $table->timestamp('checked_at');

            $table->unique(['product_id', 'chain', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_suggestion_verdicts');
    }
};
