<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('default_currency', 3)->default('EUR')->after('is_admin');
            $table->boolean('notify_via_email')->default(true)->after('default_currency');
            $table->boolean('notify_via_filament')->default(true)->after('notify_via_email');
            $table->boolean('notify_via_push')->default(false)->after('notify_via_filament');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'default_currency',
                'notify_via_email',
                'notify_via_filament',
                'notify_via_push',
            ]);
        });
    }
};
