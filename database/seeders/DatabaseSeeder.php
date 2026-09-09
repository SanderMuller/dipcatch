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
            // Feliway first: it attaches to the oldest user, and DemoSeeder
            // backdates its demo accounts — seeded the other way round, the
            // sample product would land on a random demo account.
            $this->call(ZooplusFeliwaySeeder::class);
            $this->call(DemoSeeder::class);
        }
    }
}
