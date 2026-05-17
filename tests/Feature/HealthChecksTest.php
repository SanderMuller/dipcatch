<?php declare(strict_types=1);

use App\Health\LastSuccessfulScrapeCheck;
use App\Models\Product;
use App\Models\Shop;
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

test('LastSuccessfulScrapeCheck reports OK when all active offers have a recent successful fetch', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->count(2)->for($product)->create([
        'last_success_at' => now()->subHours(2),
    ]);

    $result = new LastSuccessfulScrapeCheck()->run();

    expect($result->status->value)->toBe('ok')
        ->and($result->shortSummary)->toBe('0/2 stale');
});

test('LastSuccessfulScrapeCheck warns when an offer is stale beyond warn threshold', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['last_success_at' => now()->subHours(2)]);
    Shop::factory()->for($product)->create(['last_success_at' => now()->subHours(60)]);

    $result = new LastSuccessfulScrapeCheck()->warnAfterHours(48)->failAfterHours(96)->run();

    expect($result->status->value)->toBe('warning')
        ->and($result->shortSummary)->toBe('1/2 stale');
});

test('LastSuccessfulScrapeCheck fails when an offer is critically stale', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['last_success_at' => now()->subHours(120)]);

    $result = new LastSuccessfulScrapeCheck()->warnAfterHours(48)->failAfterHours(96)->run();

    expect($result->status->value)->toBe('failed');
});

test('LastSuccessfulScrapeCheck does not flip on a recent successful offer', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['last_success_at' => now()->subMinutes(20)]);

    $result = new LastSuccessfulScrapeCheck()->warnAfterHours(48)->failAfterHours(96)->run();

    expect($result->status->value)->toBe('ok')
        ->and($result->shortSummary)->toBe('0/1 stale');
});

test('LastSuccessfulScrapeCheck treats dead offers as not relevant', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->dead()->create(['last_success_at' => now()->subHours(120)]);

    $result = new LastSuccessfulScrapeCheck()->run();

    expect($result->status->value)->toBe('ok');
});

test('LastSuccessfulScrapeCheck treats inactive products as not relevant', function (): void {
    $product = Product::factory()->inactive()->create();
    Shop::factory()->for($product)->create(['last_success_at' => now()->subHours(120)]);

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
