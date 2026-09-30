<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('web_searches')) {
            Schema::create('web_searches', function (Blueprint $table): void {
                // One web search, shared by every product that asks the same
                // query: a search result says nothing about who asked.
                $table->id();
                $table->string('query_hash', 64)->unique();
                $table->string('query');
                $table->json('results');
                $table->timestamp('searched_at');
            });
        }

        if (! Schema::hasTable('web_shop_findings')) {
            Schema::create('web_shop_findings', function (Blueprint $table): void {
                // One page a web search returned for one product, and how far
                // its checks got.
                $table->id();
                $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('web_search_id')->nullable()->constrained()->nullOnDelete();
                $table->text('url');
                $table->string('url_hash', 64);
                $table->string('host');
                $table->text('add_url')->nullable();
                $table->string('served_host')->nullable();
                $table->string('search_title');
                $table->text('snippet')->nullable();
                $table->float('first_chance')->nullable();
                $table->string('page_title')->nullable();
                $table->decimal('page_pack_quantity', 12, 3)->nullable();
                $table->string('page_pack_unit')->nullable();
                $table->decimal('page_price', 10, 2)->nullable();
                $table->string('page_currency', 3)->nullable();
                $table->string('page_gtin')->nullable();
                $table->string('matched_gtin')->nullable();
                $table->json('checked_gtins')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->float('second_chance')->nullable();
                $table->string('status');
                $table->string('failure')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->string('fingerprint', 64);
                $table->unsignedInteger('generation')->default(0);
                $table->timestamp('checked_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();

                $table->unique(['product_id', 'url_hash']);
                $table->index(['product_id', 'status']);
            });
        }

        if (! Schema::hasTable('web_discoveries')) {
            Schema::create('web_discoveries', function (Blueprint $table): void {
                // Discovery for one product as a whole, written before any job
                // is queued, so the page knows it is on its way.
                $table->foreignUuid('product_id')->primary()->constrained()->cascadeOnDelete();
                $table->foreignId('web_search_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('search_searched_at')->nullable();
                $table->string('state');
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('finished_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_discoveries');
        Schema::dropIfExists('web_shop_findings');
        Schema::dropIfExists('web_searches');
    }
};
