<?php declare(strict_types=1);

use App\Jobs\SendDailyDigest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    // Pin "now" to 09:30 UTC. Europe/Amsterdam is +01:00 in winter (Jan),
    // so local time at that moment is 10:30 — past the 09:00 send-hour.
    // Other timezones are computed relative to the same instant.
    Date::setTestNow(CarbonImmutable::create(2026, 1, 15, 9, 30, 0, 'UTC'));
});

afterEach(function (): void {
    Date::setTestNow();
});

test('dispatches digest for users in a timezone where local 09:00 has passed', function (): void {
    $due = User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'notify_via_email' => true,
        'digest_processed_until' => null,
    ]);

    $this->artisan('dipcatch:dispatch-daily-digests')->assertSuccessful();

    Queue::assertPushed(SendDailyDigest::class, fn (SendDailyDigest $job): bool => $job->user->is($due));
});

test('skips users whose local 09:00 has NOT passed', function (): void {
    // 09:30 UTC = 02:30 in Los Angeles. Not yet 09:00 local.
    User::factory()->create([
        'timezone' => 'America/Los_Angeles',
        'notify_via_email' => true,
        'digest_processed_until' => null,
    ]);

    $this->artisan('dipcatch:dispatch-daily-digests')->assertSuccessful();

    Queue::assertNotPushed(SendDailyDigest::class);
});

test('skips users with notify_via_email = false', function (): void {
    User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'notify_via_email' => false,
        'digest_processed_until' => null,
    ]);

    $this->artisan('dipcatch:dispatch-daily-digests')->assertSuccessful();

    Queue::assertNotPushed(SendDailyDigest::class);
});

test('skips users who already received today\'s digest', function (): void {
    // digest_processed_until = 08:00 UTC same day = 09:00 local Amsterdam,
    // which falls within today's local day. Not due again.
    User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'notify_via_email' => true,
        'digest_processed_until' => CarbonImmutable::create(2026, 1, 15, 8, 0, 0, 'UTC'),
    ]);

    $this->artisan('dipcatch:dispatch-daily-digests')->assertSuccessful();

    Queue::assertNotPushed(SendDailyDigest::class);
});

test('redispatches users whose digest last ran on a prior local day', function (): void {
    // last sent yesterday morning local time = due today.
    $due = User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'notify_via_email' => true,
        'digest_processed_until' => CarbonImmutable::create(2026, 1, 14, 8, 0, 0, 'UTC'),
    ]);

    $this->artisan('dipcatch:dispatch-daily-digests')->assertSuccessful();

    Queue::assertPushed(SendDailyDigest::class, fn (SendDailyDigest $job): bool => $job->user->is($due));
});

test('queues every due account in one run, even with earlier jobs still queued', function (): void {
    // A cap used to spend itself on accounts whose job had not run yet: the
    // unique lock skipped their re-dispatch, yet they filled the batch, and
    // the same low ids came first on every tick. The old knob, set this low,
    // would have queued nobody new on the second run.
    config()->set('dipcatch.digest.batch_size', 3);
    $queuedEarlier = User::factory()->count(3)->create([
        'timezone' => 'Europe/Amsterdam',
        'notify_via_email' => true,
        'digest_processed_until' => null,
    ]);

    $this->artisan('dipcatch:dispatch-daily-digests')->assertSuccessful();

    $late = User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'notify_via_email' => true,
        'digest_processed_until' => null,
    ]);

    $this->artisan('dipcatch:dispatch-daily-digests')->assertSuccessful();

    Queue::assertPushed(SendDailyDigest::class, $queuedEarlier->count() + 1);
    Queue::assertPushed(SendDailyDigest::class, fn (SendDailyDigest $job): bool => $job->user->is($late));
});

test('dispatched jobs land on the default queue', function (): void {
    User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'notify_via_email' => true,
        'digest_processed_until' => null,
    ]);

    $this->artisan('dipcatch:dispatch-daily-digests')->assertSuccessful();

    Queue::assertPushed(fn (SendDailyDigest $job): bool => $job->queue === null);
});
