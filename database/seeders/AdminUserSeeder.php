<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('dipcatch.admin.email');
        $password = config('dipcatch.admin.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command->warn('AdminUserSeeder skipped: set ADMIN_EMAIL and ADMIN_PASSWORD to seed the admin user.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => config('dipcatch.admin.name', 'Admin'),
                'password' => Hash::make($password),
                'is_admin' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
