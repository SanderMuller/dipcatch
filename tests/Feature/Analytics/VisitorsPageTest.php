<?php declare(strict_types=1);

use App\Analytics\Enums\PageViewSection;
use App\Analytics\Models\PageView;
use App\Analytics\PageViewStats;
use App\Filament\Admin\Widgets\Visitors\AppUsageWidget;
use App\Filament\Admin\Widgets\Visitors\SignupSourcesWidget;
use App\Filament\Admin\Widgets\Visitors\TopReferrersWidget;
use App\Filament\Admin\Widgets\Visitors\VisitorRange;
use App\Filament\Admin\Widgets\Visitors\VisitorStatsWidget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

use function Pest\Laravel\artisan;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00'));
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel('admin');
});

it('renders the page for an admin', function (): void {
    $this->get('/admin/visitors')
        ->assertOk()
        ->assertSee('Visitors')
        ->assertSee('Visitors per day');
});

it('is forbidden for a non-admin', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/visitors')
        ->assertForbidden();
});

it('keeps the visitor widgets off the main dashboard', function (): void {
    $this->get('/admin')
        ->assertOk()
        ->assertDontSee('Visitors per day');
});

it('counts past days from the totals and today from the raw rows', function (): void {
    PageView::factory()->count(3)->create([PageView::VIEWED_AT => now()->subDays(2), PageView::REFERRER_HOST => 'google.com']);
    artisan('analytics:rollup', ['--date' => now()->subDays(2)->toDateString()]);
    PageView::factory()->count(2)->create([PageView::VIEWED_AT => now(), PageView::REFERRER_HOST => 'google.com']);

    livewire(VisitorStatsWidget::class, ['pageFilters' => ['range' => '7']])
        ->assertSeeInOrder(['Page views', '5']);

    livewire(TopReferrersWidget::class, ['pageFilters' => ['range' => '7']])
        ->assertSee('google.com')
        ->assertSee('5');
});

it('changes the numbers with the range filter', function (): void {
    PageView::factory()->count(4)->create([PageView::VIEWED_AT => now()->subDays(20)]);
    artisan('analytics:rollup', ['--date' => now()->subDays(20)->toDateString()]);
    PageView::factory()->create([PageView::VIEWED_AT => now()]);

    livewire(VisitorStatsWidget::class, ['pageFilters' => ['range' => '7']])
        ->assertSeeInOrder(['Page views', '1', 'Visitor-days']);

    livewire(VisitorStatsWidget::class, ['pageFilters' => ['range' => '30']])
        ->assertSeeInOrder(['Page views', '5', 'Visitor-days']);
});

it('shows app users and their share, and n/a past 90 days', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    PageView::factory()->linked($first)->create([PageView::ROUTE_NAME => 'app.products.index', PageView::VIEWED_AT => now()]);
    PageView::factory()->linked($second)->create([PageView::ROUTE_NAME => 'app.dashboard', PageView::VIEWED_AT => now()]);
    PageView::factory()->linked($first)->create([PageView::ROUTE_NAME => 'app.dashboard', PageView::VIEWED_AT => now()]);

    livewire(AppUsageWidget::class, ['pageFilters' => ['range' => '30']])
        ->assertSee('app.dashboard')
        ->assertSee('100%')
        ->assertSee('50%');

    livewire(VisitorStatsWidget::class, ['pageFilters' => ['range' => 'custom', 'from' => '2026-06-01', 'until' => '2026-11-20']])
        ->assertSeeInOrder(['App users', 'n/a']);

    livewire(AppUsageWidget::class, ['pageFilters' => ['range' => 'custom', 'from' => '2026-06-01', 'until' => '2026-11-20']])
        ->assertSee('n/a');
});

it('lists signups by their source', function (): void {
    User::factory()->create(['signup_referrer_host' => 'news.example.test', 'signup_utm_source' => 'newsletter']);
    User::factory()->create(['signup_referrer_host' => 'news.example.test']);
    User::factory()->create(['created_at' => now()->subDays(60), 'signup_referrer_host' => 'old.example.test']);

    livewire(SignupSourcesWidget::class, ['pageFilters' => ['range' => '30']])
        ->assertSee('news.example.test')
        ->assertSee('newsletter')
        ->assertDontSee('old.example.test');

    livewire(VisitorStatsWidget::class, ['pageFilters' => ['range' => '30']])
        ->assertSeeInOrder(['Signups with a source', '2']);
});

it('resolves the date range from the filters', function (?array $filters, string $from, string $until): void {
    $range = VisitorRange::fromFilters($filters);

    expect($range->from->toDateString())->toBe($from)
        ->and($range->until->toDateString())->toBe($until);
})->with([
    'default' => [null, '2026-10-22', '2026-11-20'],
    '7 days' => [['range' => '7'], '2026-11-14', '2026-11-20'],
    'custom' => [['range' => 'custom', 'from' => '2026-11-01', 'until' => '2026-11-10'], '2026-11-01', '2026-11-10'],
    'custom reversed' => [['range' => 'custom', 'from' => '2026-11-10', 'until' => '2026-11-01'], '2026-11-01', '2026-11-10'],
    'custom without from' => [['range' => 'custom'], '2026-10-22', '2026-11-20'],
    'custom in the future' => [['range' => 'custom', 'from' => '2026-11-15', 'until' => '2027-01-01'], '2026-11-15', '2026-11-20'],
]);

it('gives the chart one point per day and section, zero on empty days', function (): void {
    PageView::factory()->create([PageView::VIEWED_AT => now()->subDay(), PageView::SECTION => PageViewSection::Marketing]);
    artisan('analytics:rollup');
    PageView::factory()->linked()->create([PageView::VIEWED_AT => now()]);

    $days = app(PageViewStats::class)->dailyVisitors(now()->toImmutable()->subDays(2), now()->toImmutable()->startOfDay());

    expect($days)->toBe([
        '2026-11-18' => ['marketing' => 0, 'app' => 0],
        '2026-11-19' => ['marketing' => 1, 'app' => 0],
        '2026-11-20' => ['marketing' => 0, 'app' => 1],
    ]);
});

it('shows app users only for ranges the linked-row prune has not reached', function (): void {
    $stats = app(PageViewStats::class);
    $today = now()->toImmutable()->startOfDay();

    expect($stats->appUsers($today->subDays(89), $today))->toBe(0)
        ->and($stats->appUsers($today->subDays(90), $today))->toBeNull();
});
