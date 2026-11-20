<?php declare(strict_types=1);

use App\Analytics\Enums\PageViewSection;
use App\Analytics\Enums\StatPeriod;
use App\Analytics\Models\PageView;
use App\Analytics\Models\PageViewPeriodStat;
use App\Analytics\PageViewStats;
use App\Filament\Admin\Widgets\Visitors\UniqueVisitorsWidget;
use App\Livewire\Settings\UsageTracking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

use function Pest\Laravel\artisan;
use function Pest\Livewire\livewire;

function analyticsPeriodStat(StatPeriod $period, string $startsOn, string $section = PageViewPeriodStat::ALL_SECTIONS): ?PageViewPeriodStat
{
    return PageViewPeriodStat::query()
        ->where(PageViewPeriodStat::PERIOD, $period->value)
        ->whereDate(PageViewPeriodStat::STARTS_ON, $startsOn)
        ->where(PageViewPeriodStat::SECTION, $section)
        ->first();
}

beforeEach(function (): void {
    // Friday 20 November 2026; the week started on Monday 16 November.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 00:20:00'));
});

it('counts each signed-in user once per week, month and section', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    PageView::factory()->linked($first)->count(2)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-16 10:00'), PageView::SECTION => PageViewSection::App]);
    PageView::factory()->linked($first)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-18 10:00'), PageView::SECTION => PageViewSection::Marketing]);
    PageView::factory()->linked($second)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-03 10:00'), PageView::SECTION => PageViewSection::App]);
    PageView::factory()->anonymous()->count(3)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-17 10:00')]);

    artisan('analytics:rollup')->assertSuccessful();

    expect(analyticsPeriodStat(StatPeriod::Week, '2026-11-16')?->users)->toBe(1)
        ->and(analyticsPeriodStat(StatPeriod::Week, '2026-11-16')?->visitors)->toBe(1)
        ->and(analyticsPeriodStat(StatPeriod::Week, '2026-11-16', 'app')?->users)->toBe(1)
        ->and(analyticsPeriodStat(StatPeriod::Week, '2026-11-16', 'marketing')?->users)->toBe(1)
        ->and(analyticsPeriodStat(StatPeriod::Month, '2026-11-01')?->users)->toBe(2)
        ->and(analyticsPeriodStat(StatPeriod::Month, '2026-11-01', 'app')?->users)->toBe(2);
});

it('counts the current period only up to yesterday', function (): void {
    $user = User::factory()->create();
    PageView::factory()->linked($user)->create([PageView::VIEWED_AT => now()]);

    artisan('analytics:rollup')->assertSuccessful();

    expect(analyticsPeriodStat(StatPeriod::Week, '2026-11-16'))->toBeNull();
});

it('rebuilds a week that spans two months, and gives the same rows on a rerun', function (): void {
    $november = User::factory()->create();
    $december = User::factory()->create();
    PageView::factory()->linked($november)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-30 10:00')]);
    PageView::factory()->linked($december)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-12-01 10:00')]);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:20:00'));
    artisan('analytics:rollup')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-12-03 00:20:00'));
    artisan('analytics:rollup')->assertSuccessful();
    artisan('analytics:rollup')->assertSuccessful();

    expect(analyticsPeriodStat(StatPeriod::Week, '2026-11-30')?->users)->toBe(2)
        ->and(analyticsPeriodStat(StatPeriod::Month, '2026-11-01')?->users)->toBe(1)
        ->and(analyticsPeriodStat(StatPeriod::Month, '2026-12-01')?->users)->toBe(1)
        ->and(PageViewPeriodStat::query()->where(PageViewPeriodStat::PERIOD, StatPeriod::Week->value)->count())->toBe(2);
});

it('closes a 31-day month on the first of the next month', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:20:00'));
    $user = User::factory()->create();
    PageView::factory()->linked($user)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-10-01 08:00')]);

    artisan('analytics:rollup')->assertSuccessful()->doesntExpectOutputToContain('Skipped');

    expect(analyticsPeriodStat(StatPeriod::Month, '2026-10-01')?->users)->toBe(1);
});

it('skips a period that started before the prune window and keeps its stored row', function (): void {
    PageViewPeriodStat::query()->insert([
        PageViewPeriodStat::PERIOD => StatPeriod::Month->value,
        PageViewPeriodStat::STARTS_ON => '2026-10-01',
        PageViewPeriodStat::SECTION => PageViewPeriodStat::ALL_SECTIONS,
        PageViewPeriodStat::VISITORS => 7,
        PageViewPeriodStat::USERS => 7,
    ]);
    PageView::factory()->linked()->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-10-26 08:00')]);

    artisan('analytics:rollup', ['--date' => '2026-10-26'])
        ->expectsOutputToContain('Skipped the month from 2026-10-01')
        ->assertSuccessful();

    expect(analyticsPeriodStat(StatPeriod::Month, '2026-10-01')?->users)->toBe(7)
        ->and(analyticsPeriodStat(StatPeriod::Week, '2026-10-26')?->users)->toBe(1);
});

it('stops counting a user in the next rebuild after they opt out', function (): void {
    $user = User::factory()->create();
    PageView::factory()->linked($user)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-17 10:00')]);
    artisan('analytics:rollup')->assertSuccessful();

    $this->actingAs($user);
    Livewire\Livewire::test(UsageTracking::class)->set('usage_tracking', false)->call('save');
    artisan('analytics:rollup')->assertSuccessful();

    expect(analyticsPeriodStat(StatPeriod::Week, '2026-11-16'))->toBeNull();
});

it('lists the weeks and months in the range, newest first, and marks the current ones', function (): void {
    PageView::factory()->linked()->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-02 10:00')]);
    PageView::factory()->linked()->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-17 10:00')]);
    artisan('analytics:rollup', ['--date' => '2026-11-02']);
    artisan('analytics:rollup');

    expect(collect(app(PageViewStats::class)->periods(CarbonImmutable::parse('2026-11-14'), CarbonImmutable::parse('2026-11-20')))
        ->map(fn (array $row): string => $row['period']->label($row['starts_on']) . ($row['complete'] ? '' : '*'))
        ->all())->toBe(['Week 47, 2026*', 'November 2026*']);

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel('admin');

    livewire(UniqueVisitorsWidget::class, ['pageFilters' => ['range' => '30']])
        ->assertSeeInOrder(['Week 47, 2026 (up to yesterday)', 'Week 45, 2026', 'November 2026 (up to yesterday)']);
});

it('lists the month a range starts in when the range starts on the 31st', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-04-05 00:20:00'));
    PageViewPeriodStat::query()->insert([
        PageViewPeriodStat::PERIOD => StatPeriod::Month->value,
        PageViewPeriodStat::STARTS_ON => '2026-03-01',
        PageViewPeriodStat::SECTION => PageViewPeriodStat::ALL_SECTIONS,
        PageViewPeriodStat::VISITORS => 4,
        PageViewPeriodStat::USERS => 4,
    ]);

    expect(app(PageViewStats::class)->periods(CarbonImmutable::parse('2026-03-31'), CarbonImmutable::parse('2026-04-04')))
        ->toHaveCount(1)
        ->sequence(fn ($row) => $row->starts_on->toDateString()->toBe('2026-03-01'));
});
