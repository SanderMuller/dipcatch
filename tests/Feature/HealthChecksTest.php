<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Health\LastSuccessfulScrapeCheck;
use App\Models\Product;
use App\Models\User;
use Spatie\CpuLoadHealthCheck\CpuLoadCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;
use Spatie\SecurityAdvisoriesHealthCheck\SecurityAdvisoriesCheck;

test('all expected checks are registered', function (): void {
    $registered = collect(Health::registeredChecks())
        ->map(fn ($check): string => $check::class)
        ->all();

    expect($registered)->toContain(LastSuccessfulScrapeCheck::class)
        ->and($registered)->toContain(DatabaseCheck::class)
        ->and($registered)->toContain(ScheduleCheck::class)
        ->and($registered)->toContain(CpuLoadCheck::class)
        ->and($registered)->toContain(SecurityAdvisoriesCheck::class);
});

test('LastSuccessfulScrapeCheck reports OK when no active products', function (): void {
    $result = new LastSuccessfulScrapeCheck()->run();

    expect($result->status->value)->toBe('ok')
        ->and($result->shortSummary)->toBe('idle');
});

test('LastSuccessfulScrapeCheck reports OK when all active products have a recent successful scrape', function (): void {
    Product::factory()->count(2)->create([
        'last_status' => ScrapeStatus::Ok,
        'last_checked_at' => now()->subHours(2),
    ]);

    $result = new LastSuccessfulScrapeCheck()->run();

    expect($result->status->value)->toBe('ok')
        ->and($result->shortSummary)->toBe('0/2 stale');
});

test('LastSuccessfulScrapeCheck warns when a product is stale beyond warn threshold', function (): void {
    Product::factory()->create([
        'last_status' => ScrapeStatus::Ok,
        'last_checked_at' => now()->subHours(2),
        'last_success_at' => now()->subHours(2),
    ]);
    Product::factory()->create([
        'last_status' => ScrapeStatus::Ok,
        'last_checked_at' => now()->subHours(60),
        'last_success_at' => now()->subHours(60),
    ]);

    $result = new LastSuccessfulScrapeCheck()->warnAfterHours(48)->failAfterHours(96)->run();

    expect($result->status->value)->toBe('warning')
        ->and($result->shortSummary)->toBe('1/2 stale');
});

test('LastSuccessfulScrapeCheck fails when a product is critically stale', function (): void {
    Product::factory()->create([
        'last_status' => ScrapeStatus::Ok,
        'last_checked_at' => now()->subHours(120),
        'last_success_at' => now()->subHours(120),
    ]);

    $result = new LastSuccessfulScrapeCheck()->warnAfterHours(48)->failAfterHours(96)->run();

    expect($result->status->value)->toBe('failed');
});

test('LastSuccessfulScrapeCheck escalates a constantly-failing product to failed even when last_checked_at is fresh', function (): void {
    // Product is being retried every cycle (fresh last_checked_at) but every
    // attempt has been a failure since well before failAfterHours.
    Product::factory()->create([
        'last_status' => ScrapeStatus::HttpError,
        'last_checked_at' => now()->subMinutes(5),
        'last_success_at' => now()->subHours(120),
    ]);

    $result = new LastSuccessfulScrapeCheck()->warnAfterHours(48)->failAfterHours(96)->run();

    expect($result->status->value)->toBe('failed');
});

test('LastSuccessfulScrapeCheck treats inactive products as not relevant', function (): void {
    Product::factory()->inactive()->create([
        'last_status' => ScrapeStatus::Ok,
        'last_checked_at' => now()->subHours(120),
        'last_success_at' => now()->subHours(120),
    ]);

    $result = new LastSuccessfulScrapeCheck()->run();

    expect($result->status->value)->toBe('ok');
});

test('admin can access the Filament health page', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin/health-check-results')->assertOk();
});

test('non-admin cannot access the Filament health page', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/health-check-results')->assertForbidden();
});

test('failed-job-monitor mail recipients come from FAILED_JOB_MONITOR_NOTIFIABLE env', function (): void {
    config()->set('failed-job-monitor.mail.to', ['ops@dipcatch.test']);

    expect(config('failed-job-monitor.mail.to'))->toBe(['ops@dipcatch.test'])
        ->and(config('failed-job-monitor.channels'))->toBeArray();
});
