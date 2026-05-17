<?php declare(strict_types=1);

use App\Models\User;
use App\Rules\IanaTimezone;
use Carbon\CarbonImmutable;

dataset('valid_timezones', [
    'Europe/Amsterdam' => 'Europe/Amsterdam',
    'America/New_York' => 'America/New_York',
    'UTC' => 'UTC',
    'Asia/Tokyo' => 'Asia/Tokyo',
]);

test('valid IANA timezones pass', function (string $tz): void {
    expect(runRule(new IanaTimezone(), 'timezone', $tz))->toBe([]);
})->with('valid_timezones');

dataset('invalid_timezones', [
    'UTC+1 offset format' => 'UTC+1',
    'made-up city' => 'Europe/Atlantis',
    'lowercase variant' => 'europe/amsterdam',
    'random string' => 'foo bar',
]);

test('invalid timezones fail with a descriptive error', function (string $tz): void {
    expect(runRule(new IanaTimezone(), 'timezone', $tz))->not->toBe([])
        ->and(runRule(new IanaTimezone(), 'timezone', $tz)[0])->toContain('timezone');
})->with('invalid_timezones');

test('empty value is not a timezone failure (required validates separately)', function (): void {
    expect(runRule(new IanaTimezone(), 'timezone', ''))->toBe([]);
});

test('User model round-trips timezone + last_digest_sent_at', function (): void {
    $user = User::factory()->create([
        'timezone' => 'Asia/Tokyo',
        'last_digest_sent_at' => now()->subDay(),
    ]);

    $user->refresh();

    expect($user->timezone)->toBe('Asia/Tokyo')
        ->and($user->last_digest_sent_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('new users default to Europe/Amsterdam timezone with null last_digest_sent_at', function (): void {
    $user = User::factory()->create();

    expect($user->timezone)->toBe('Europe/Amsterdam')
        ->and($user->last_digest_sent_at)->toBeNull();
});
