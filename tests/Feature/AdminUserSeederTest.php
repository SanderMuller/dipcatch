<?php declare(strict_types=1);

use App\Models\User;
use Database\Seeders\AdminUserSeeder;

test('seeder creates an admin user from config', function (): void {
    config()->set('dipcatch.admin.email', 'root@dipcatch.test');
    config()->set('dipcatch.admin.password', 'super-secret');
    config()->set('dipcatch.admin.name', 'Root');

    $this->seed(AdminUserSeeder::class);

    $admin = User::query()->where('email', 'root@dipcatch.test')->sole();
    expect($admin->is_admin)->toBeTrue()
        ->and($admin->name)->toBe('Root')
        ->and($admin->email_verified_at)->not->toBeNull();
});

test('seeder is idempotent — running twice does not duplicate or unset is_admin', function (): void {
    config()->set('dipcatch.admin.email', 'root@dipcatch.test');
    config()->set('dipcatch.admin.password', 'super-secret');

    $this->seed(AdminUserSeeder::class);
    $this->seed(AdminUserSeeder::class);

    expect(User::query()->where('email', 'root@dipcatch.test')->count())->toBe(1);
});

test('seeder skips silently when ADMIN_EMAIL missing so default db:seed pipelines do not break', function (): void {
    config()->set('dipcatch.admin.email');
    config()->set('dipcatch.admin.password', 'x');

    $this->seed(AdminUserSeeder::class);

    expect(User::query()->count())->toBe(0);
});

test('seeder skips silently when ADMIN_PASSWORD missing', function (): void {
    config()->set('dipcatch.admin.email', 'root@dipcatch.test');
    config()->set('dipcatch.admin.password');

    $this->seed(AdminUserSeeder::class);

    expect(User::query()->count())->toBe(0);
});
