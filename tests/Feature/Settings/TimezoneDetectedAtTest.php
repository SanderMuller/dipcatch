<?php declare(strict_types=1);

use App\Models\User;

test('User.timezone_detected_at defaults to null for new users', function (): void {
    $user = User::factory()->create();

    expect($user->fresh()->timezone_detected_at)->toBeNull();
});

test('User.timezone_detected_at round-trips as Carbon datetime', function (): void {
    $stamp = now()->subHour();
    $user = User::factory()->create(['timezone_detected_at' => $stamp]);

    expect($user->fresh()->timezone_detected_at)->toBeSameTimestampAs($stamp);
});
