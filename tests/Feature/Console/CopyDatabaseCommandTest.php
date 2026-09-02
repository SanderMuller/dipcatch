<?php declare(strict_types=1);

use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

/**
 * Two throwaway SQLite files stand in for the source and target engines;
 * the command's driver-specific step (sequence reset) is pgsql-only and
 * guarded, so the copy logic is what these tests exercise.
 */
beforeEach(function (): void {
    $this->sourcePath = tempnam(sys_get_temp_dir(), 'copy-src-');
    $this->targetPath = tempnam(sys_get_temp_dir(), 'copy-dst-');

    config()->set('database.connections.copy_source', ['driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => '', 'foreign_key_constraints' => true]);
    config()->set('database.connections.copy_target', ['driver' => 'sqlite', 'database' => $this->targetPath, 'prefix' => '', 'foreign_key_constraints' => true]);

    // Two vendor migrations pin their connection through config, and some
    // resolve the schema builder from the default connection rather than
    // the migrator's --database, so migrate each side as the default.
    $default = config('database.default');
    $pinnedKeys = [
        'health.result_stores.' . EloquentHealthResultStore::class . '.connection',
        'webpush.database_connection',
    ];
    $pinned = array_map(fn (string $key): mixed => config($key), $pinnedKeys);
    foreach (['copy_source', 'copy_target'] as $connection) {
        config()->set('database.default', $connection);
        foreach ($pinnedKeys as $key) {
            config()->set($key, $connection);
        }
        DB::purge($connection);
        Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
    }
    config()->set('database.default', $default);
    foreach ($pinnedKeys as $i => $key) {
        config()->set($key, $pinned[$i]);
    }
});

afterEach(function (): void {
    DB::purge('copy_source');
    DB::purge('copy_target');
    @unlink($this->sourcePath);
    @unlink($this->targetPath);
});

function seedSource(): array
{
    $default = config('database.default');
    config()->set('database.default', 'copy_source');

    $user = User::factory()->create(['notify_via_push' => true, 'is_admin' => false]);
    $product = Product::factory()->for($user)->create(['active' => true]);
    $shop = Shop::factory()->for($product)->create(['active' => false, 'current_in_stock' => true, 'current_price' => '1.69']);
    PriceCheck::factory()->count(3)->for($shop)->create();

    config()->set('database.default', $default);

    return ['user' => $user, 'product' => $product, 'shop' => $shop];
}

test('copies every table in dependency order with matching row counts', function (): void {
    seedSource();

    $this->artisan('dipcatch:copy-database', ['--from' => 'copy_source', '--to' => 'copy_target'])
        ->assertSuccessful()
        ->expectsOutputToContain('row counts match');

    foreach (['users', 'products', 'shops', 'price_checks'] as $table) {
        expect(DB::connection('copy_target')->table($table)->count())
            ->toBe(DB::connection('copy_source')->table($table)->count(), $table);
    }

    // Runtime tables are not copied.
    expect(DB::connection('copy_target')->table('sessions')->count())->toBe(0);
});

test('preserves booleans, decimals and nullable columns', function (): void {
    ['shop' => $shop] = seedSource();

    $this->artisan('dipcatch:copy-database', ['--from' => 'copy_source', '--to' => 'copy_target'])->assertSuccessful();

    $copied = DB::connection('copy_target')->table('shops')->where('id', $shop->id)->first();

    expect((bool) $copied->active)->toBeFalse()
        ->and((bool) $copied->current_in_stock)->toBeTrue()
        ->and($copied->current_price)->toEqual(1.69)
        ->and($copied->last_error)->toBeNull();
});

test('refuses to write into a non-empty target unless --truncate is given, then replaces the rows', function (): void {
    seedSource();

    DB::connection('copy_target')->table('users')->insert([
        'name' => 'Stale', 'email' => 'stale@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('dipcatch:copy-database', ['--from' => 'copy_source', '--to' => 'copy_target'])
        ->assertFailed()
        ->expectsOutputToContain('not empty');

    $this->artisan('dipcatch:copy-database', ['--from' => 'copy_source', '--to' => 'copy_target', '--truncate' => true])
        ->assertSuccessful();

    expect(DB::connection('copy_target')->table('users')->where('email', 'stale@example.test')->exists())->toBeFalse()
        ->and(DB::connection('copy_target')->table('users')->count())->toBe(1);
});

test('dry run writes nothing', function (): void {
    seedSource();

    $this->artisan('dipcatch:copy-database', ['--from' => 'copy_source', '--to' => 'copy_target', '--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('nothing written');

    expect(DB::connection('copy_target')->table('users')->count())->toBe(0);
});

test('breaks the products ↔ shops foreign-key cycle and backfills cheapest_shop_id', function (): void {
    ['product' => $product, 'shop' => $shop] = seedSource();
    DB::connection('copy_source')->table('products')->where('id', $product->id)->update(['cheapest_shop_id' => $shop->id]);

    $this->artisan('dipcatch:copy-database', ['--from' => 'copy_source', '--to' => 'copy_target'])
        ->assertSuccessful()
        ->expectsOutputToContain('products.cheapest_shop_id inserted as null first');

    expect(DB::connection('copy_target')->table('products')->where('id', $product->id)->value('cheapest_shop_id'))->toBe($shop->id);
});
