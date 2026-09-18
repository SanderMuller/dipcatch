<?php declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * A canary nobody runs, and a failure nobody is told about, are both silence.
 * The schedule and the mail settings are the delivery path, so they are pinned
 * here rather than left to a config file nobody reads.
 */
function scheduleListing(): string
{
    Artisan::call('schedule:list');

    return Artisan::output();
}

/**
 * The schedule is registered when the console kernel boots, so the listing
 * runs first and the events are read from the booted schedule.
 *
 * @return Collection<int, Event>
 */
function scheduledEvents(string $needle): Collection
{
    scheduleListing();

    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, $needle))
        ->values();
}

test('the canary and the health run are both scheduled daily', function (): void {
    $listing = scheduleListing();

    expect($listing)->toContain('dipcatch:canary')
        ->and($listing)->toContain('health:check');
});

test('the schedule heartbeat runs every minute', function (): void {
    // Spatie's registered ScheduleCheck fails with "The schedule did not run
    // yet" until this writes its cache key, so without it the first health
    // mail is a false alarm about the scheduler itself.
    $heartbeat = scheduledEvents('health:schedule-check-heartbeat');

    expect($heartbeat)->toHaveCount(1)
        ->and($heartbeat->first()->expression)->toBe('* * * * *');
});

test('the scheduled canary runs on one server without overlapping', function (): void {
    $events = scheduledEvents('dipcatch:canary');

    // Two instances fetching every host at once would double the traffic and
    // race each other's rows.
    expect($events)->toHaveCount(1)
        ->and($events->first()->onOneServer)->toBeTrue()
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

test('only failures are mailed', function (): void {
    // A warning never reaches the inbox, which is why every canary condition
    // that needs a person is a failure rather than a warning.
    expect(config('health.notifications.only_on_failure'))->toBeTrue();
});

test('the health recipient is read from the environment, not the package default', function (): void {
    /** @var array<string, mixed> $fresh */
    $fresh = require base_path('config/health.php');

    $recipients = data_get($fresh, 'notifications.mail.to');

    // The package ships `your@example.com`, which silently mails nobody.
    // The value here follows the same pair the failed-job monitor reads, so a
    // deployment that configures one configures both.
    expect($recipients)->toBeArray()
        ->and($recipients)->not->toContain('your@example.com')
        ->and(file_get_contents(base_path('config/health.php')))->toContain('FAILED_JOB_MONITOR_NOTIFIABLE');
});
