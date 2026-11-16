<?php declare(strict_types=1);

use App\Rules\IanaTimezone;

dataset('valid_timezones', [
    'Europe/Amsterdam' => 'Europe/Amsterdam',
    'UTC' => 'UTC',
]);

test('valid IANA timezones pass', function (string $tz): void {
    expect(runRule(new IanaTimezone(), 'timezone', $tz))->toBeEmpty();
})->with('valid_timezones');

dataset('invalid_timezones', [
    'UTC+1 offset format' => 'UTC+1',
    'made-up city' => 'Europe/Atlantis',
    'lowercase variant' => 'europe/amsterdam',
]);

test('invalid timezones fail with a descriptive error', function (string $tz): void {
    expect(runRule(new IanaTimezone(), 'timezone', $tz))->not->toBeEmpty()
        ->and(runRule(new IanaTimezone(), 'timezone', $tz)[0])->toContain('timezone');
})->with('invalid_timezones');

test('empty value is not a timezone failure (required validates separately)', function (): void {
    expect(runRule(new IanaTimezone(), 'timezone', ''))->toBeEmpty();
});
