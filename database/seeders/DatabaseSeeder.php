<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CheckjebonPrice;
use Database\Seeders\Demo\DatasetCatalog;
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
            $this->refreshPriceDataset();

            // Feliway first: it attaches to the oldest user, and DemoSeeder
            // backdates its demo accounts — seeded the other way round, the
            // sample product would land on a random demo account.
            $this->call(ZooplusFeliwaySeeder::class);
            $this->call(DemoSeeder::class);
        }
    }

    /**
     * Fill the checkjebon price dataset when it is empty.
     *
     * `migrate:fresh` drops it along with everything else, and it is the table
     * {@see DatasetCatalog} draws its real products
     * from. Without this the demo silently fell back to invented ones — a
     * catalog of plausible names on paths that answer "uit het assortiment".
     *
     * Never in a test: this fetches about ten megabytes from GitHub, and a
     * suite that reaches the network is a suite that fails when the network
     * does. A failed refresh is a warning rather than a stop, because a seed
     * with invented products still beats no seed at all.
     */
    private function refreshPriceDataset(): void
    {
        if (app()->runningUnitTests() || CheckjebonPrice::query()->count() > 0) {
            return;
        }

        $this->command?->info('Fetching the checkjebon price dataset, so the demo can track real products…');

        $this->callSilent('dipcatch:refresh-checkjebon');

        if (CheckjebonPrice::query()->count() === 0) {
            $this->command?->warn('Could not fetch the price dataset. The demo will use invented products instead.');
        }
    }
}
