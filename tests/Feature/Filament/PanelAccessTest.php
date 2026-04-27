<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('guest visiting the app panel is redirected to login', function (): void {
    $this->get('/app')->assertRedirect(route('login'));
});

test('guest visiting the admin panel is redirected to login', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
});

test('authenticated non-admin can access the app panel', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/app')->assertOk();
});

test('authenticated non-admin cannot access the admin panel', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')->assertForbidden();
});

test('admin can access the admin panel', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin')->assertOk();
});

test('admin can also access the app panel', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/app')->assertOk();
});

test('public registration is disabled (route does not exist)', function (): void {
    expect(Route::has('register'))->toBeFalse();
});
