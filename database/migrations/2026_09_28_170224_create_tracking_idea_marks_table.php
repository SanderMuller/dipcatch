<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A getting-started idea a person ticked by hand: "done", or "not for
        // me". Ideas their tracked products already cover need no row.
        Schema::create('tracking_idea_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idea');
            $table->string('state');
            $table->timestamp('marked_at');

            $table->unique(['user_id', 'idea']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_idea_marks');
    }
};
