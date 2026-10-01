<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klarna pages as a source of shop leads for web discovery. See
 * specs/klarna-shop-leads.md, section 5.4.
 *
 * Both tables are small. Nullable columns, and two with a constant default,
 * which Postgres adds without rewriting the table. Each is guarded so a retry
 * after a half-run migration skips what already exists.
 */
return new class extends Migration {
    public function up(): void
    {
        $findings = [
            'lead_url' => fn (Blueprint $table) => $table->text('lead_url')->nullable(),
            'lead_pack_quantity' => fn (Blueprint $table) => $table->decimal('lead_pack_quantity', 10, 2)->nullable(),
            'lead_pack_unit' => fn (Blueprint $table) => $table->string('lead_pack_unit')->nullable(),
            // The variant the page read picked, so Add tracks the same one.
            'variant_key' => fn (Blueprint $table) => $table->string('variant_key')->nullable(),
        ];

        foreach ($findings as $column => $add) {
            if (! Schema::hasColumn('web_shop_findings', $column)) {
                Schema::table('web_shop_findings', $add);
            }
        }

        $discoveries = [
            'klarna_url' => fn (Blueprint $table) => $table->text('klarna_url')->nullable(),
            'klarna_search_id' => fn (Blueprint $table) => $table->foreignId('klarna_search_id')->nullable()->constrained('web_searches')->nullOnDelete(),
            'klarna_generation' => fn (Blueprint $table) => $table->unsignedInteger('klarna_generation')->default(0),
            'klarna_attempts' => fn (Blueprint $table) => $table->unsignedTinyInteger('klarna_attempts')->default(0),
            'klarna_leads' => fn (Blueprint $table) => $table->json('klarna_leads')->nullable(),
            'klarna_checked_at' => fn (Blueprint $table) => $table->timestamp('klarna_checked_at')->nullable(),
        ];

        foreach ($discoveries as $column => $add) {
            if (! Schema::hasColumn('web_discoveries', $column)) {
                Schema::table('web_discoveries', $add);
            }
        }
    }

    public function down(): void
    {
        Schema::table('web_discoveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('klarna_search_id');
            $table->dropColumn(['klarna_url', 'klarna_generation', 'klarna_attempts', 'klarna_leads', 'klarna_checked_at']);
        });

        Schema::table('web_shop_findings', function (Blueprint $table): void {
            $table->dropColumn(['lead_url', 'lead_pack_quantity', 'lead_pack_unit', 'variant_key']);
        });
    }
};
