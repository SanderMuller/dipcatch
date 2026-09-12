<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Deleting a user who created invitations failed on the foreign key.
     * Keep the invitations (their tokens may still be in someone's inbox)
     * and detach the inviter instead.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropForeign(['invited_by']);
        });

        Schema::table('invitations', function (Blueprint $table): void {
            $table->unsignedBigInteger('invited_by')->nullable()->change();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropForeign(['invited_by']);
        });

        Schema::table('invitations', function (Blueprint $table): void {
            $table->unsignedBigInteger('invited_by')->nullable(false)->change();
            $table->foreign('invited_by')->references('id')->on('users');
        });
    }
};
