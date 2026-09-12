<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * One account on the Free plan, with a product already at the plan's shop
 * ceiling.
 *
 * `DemoSeeder` seeds a free account too, but it bails out as soon as its own
 * demo user exists, so it cannot add one to a database that is already
 * seeded. This seeder is safe to run at any time and takes the address from
 * the environment, which is what lets someone log in as themselves.
 *
 * The point of the ceiling is the screen behind it: the shop limit panel and
 * the upgrade path only render for an account that has actually reached it.
 */
final class FreeAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('FreeAccountSeeder skipped: never seeded in production.');

            return;
        }

        $email = config('dipcatch.demo.free_email');
        $password = config('dipcatch.demo.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command->warn('FreeAccountSeeder skipped: set DEMO_FREE_EMAIL and DEMO_PASSWORD.');

            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Free Account',
                'password' => $password,
                'email_verified_at' => now(),
                // Every route to Pro closed, so the plan resolves to Free and
                // the limits actually bite.
                'is_admin' => false,
                'comped_until' => null,
                'trial_ends_at' => null,
                'billing_blocked_at' => null,
            ],
        );

        $user->subscriptions()->where('type', Plan::SUBSCRIPTION_TYPE)->delete();

        $maxShops = Entitlements::of(Plan::Free)->maxShopsPerProduct() ?? 4;

        $this->seedProduct($user, 'Coffee beans 1 kg', '18.99', $maxShops);
        $this->seedProduct($user, 'Cat food 10 kg', '39.95', 2);

        $this->command->info('FreeAccountSeeder: ' . $email . ' seeded on the Free plan.');
        $this->command->info('  One product sits at the ' . $maxShops . '-shop ceiling, one has room for more.');
    }

    /**
     * Prices step down per shop so the cheapest one is never the first row,
     * which is what the table is there to show.
     */
    private function seedProduct(User $user, string $title, string $price, int $shopCount): void
    {
        $product = Product::query()->updateOrCreate(
            ['user_id' => $user->id, 'title' => $title],
            ['currency' => 'EUR', 'drop_threshold_pct' => 10.00, 'active' => true, 'image_url' => null],
        );

        $hosts = ['ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'spar.nl', 'aldi.nl'];

        foreach (array_slice($hosts, 0, $shopCount) as $index => $host) {
            $url = 'https://' . $host . '/product/' . Str::slug($title);

            Shop::query()->updateOrCreate(
                ['product_id' => $product->id, 'url_hash' => hash('sha256', $product->id . $url)],
                [
                    'url' => $url,
                    'host' => $host,
                    'adapter_key' => Str::before($host, '.'),
                    'currency' => 'EUR',
                    'initial_price' => $price,
                    'initial_checked_at' => now()->subDays(30),
                    'current_price' => number_format((float) $price - ($index * 0.75), 2, '.', ''),
                    'current_in_stock' => true,
                    'last_checked_at' => now()->subHour(),
                    'last_success_at' => now()->subHour(),
                    'active' => true,
                ],
            );
        }

        $product->refresh()->recomputeCheapestShop();
    }
}
