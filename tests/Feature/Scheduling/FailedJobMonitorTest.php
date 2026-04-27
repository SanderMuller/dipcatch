<?php declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Spatie\FailedJobMonitor\Notifiable;
use Spatie\FailedJobMonitor\Notification as FailedJobNotification;

test('a failed job triggers the spatie failed-job-monitor notification', function (): void {
    Notification::fake();

    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('TestJob');
    $job->shouldReceive('getConnectionName')->andReturn('sync');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn(['displayName' => 'TestJob']);
    $job->shouldReceive('uuid')->andReturn('00000000-0000-0000-0000-000000000000');
    $job->shouldIgnoreMissing();

    event(new JobFailed('sync', $job, new RuntimeException('boom')));

    Notification::assertSentTo(
        new Notifiable(),
        FailedJobNotification::class,
    );
});
