<?php declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        // Demo data for local development only — production seeds just the
        // admin user (db:seed --class=AdminUserSeeder --force).
        if (! app()->environment('production')) {
            $this->call(ZooplusFeliwaySeeder::class);
        }
    }
}
