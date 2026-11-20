<?php declare(strict_types=1);

use App\Analytics\Enums\PageViewSection;
use App\Analytics\Enums\StatDimension;
use App\Analytics\Models\PageView;
use App\Analytics\Models\PageViewDailyStat;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00'));
});

function analyticsStat(string $date, PageViewSection $section, StatDimension $dimension, string $value = ''): ?PageViewDailyStat
{
    return PageViewDailyStat::query()
        ->whereDate(PageViewDailyStat::DATE, $date)
        ->where(PageViewDailyStat::SECTION, $section->value)
        ->where(PageViewDailyStat::DIMENSION, $dimension->value)
        ->where(PageViewDailyStat::VALUE, $value)
        ->first();
}

it('rolls up yesterday into totals and distinct counts', function (): void {
    $user = User::factory()->create();
    $yesterday = now()->subDay()->setTime(9, 0);

    PageView::factory()->count(2)->create([PageView::VIEWED_AT => $yesterday, PageView::VISITOR_HASH => str_repeat('a', 32), PageView::REFERRER_HOST => 'google.com']);
    PageView::factory()->create([PageView::VIEWED_AT => $yesterday, PageView::VISITOR_HASH => str_repeat('b', 32), PageView::COUNTRY => null]);
    PageView::factory()->linked($user)->count(3)->create([PageView::VIEWED_AT => $yesterday]);
    PageView::factory()->create([PageView::VIEWED_AT => now()]);

    artisan('analytics:rollup')->assertSuccessful();

    $marketing = analyticsStat('2026-11-19', PageViewSection::Marketing, StatDimension::Total);
    $app = analyticsStat('2026-11-19', PageViewSection::App, StatDimension::Total);

    expect($marketing?->views)->toBe(3)
        ->and($marketing?->visitors)->toBe(2)
        ->and($marketing?->users)->toBe(0)
        ->and($app?->views)->toBe(3)
        ->and($app?->visitors)->toBe(1)
        ->and($app?->users)->toBe(1)
        ->and(analyticsStat('2026-11-19', PageViewSection::Marketing, StatDimension::Referrer, 'google.com')?->views)->toBe(2)
        ->and(analyticsStat('2026-11-19', PageViewSection::Marketing, StatDimension::Referrer)?->views)->toBe(1)
        ->and(analyticsStat('2026-11-19', PageViewSection::Marketing, StatDimension::Country)?->views)->toBe(1)
        ->and(analyticsStat('2026-11-19', PageViewSection::App, StatDimension::Route, 'app.dashboard')?->users)->toBe(1)
        ->and(PageViewDailyStat::query()->whereDate(PageViewDailyStat::DATE, '2026-11-20')->exists())->toBeFalse();
});

it('gives the same rows when a day is rolled up twice', function (): void {
    PageView::factory()->count(2)->create([PageView::VIEWED_AT => now()->subDay()]);

    artisan('analytics:rollup')->assertSuccessful();
    $first = PageViewDailyStat::query()->orderBy(PageViewDailyStat::DIMENSION)->get([PageViewDailyStat::DIMENSION, PageViewDailyStat::VALUE, PageViewDailyStat::VIEWS])->toArray();

    artisan('analytics:rollup')->assertSuccessful();
    $second = PageViewDailyStat::query()->orderBy(PageViewDailyStat::DIMENSION)->get([PageViewDailyStat::DIMENSION, PageViewDailyStat::VALUE, PageViewDailyStat::VIEWS])->toArray();

    expect($second)->toBe($first)
        ->and(analyticsStat('2026-11-19', PageViewSection::Marketing, StatDimension::Total)?->views)->toBe(2);
});

it('stores nothing for a day without page views', function (): void {
    artisan('analytics:rollup', ['--date' => '2026-11-10'])->assertSuccessful();

    expect(PageViewDailyStat::query()->count())->toBe(0);
});

it('rolls up a given date and refuses a malformed one', function (): void {
    PageView::factory()->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-10 08:00:00')]);

    artisan('analytics:rollup', ['--date' => '2026-11-10'])->assertSuccessful();
    artisan('analytics:rollup', ['--date' => '10-11-2026'])->assertFailed();

    expect(analyticsStat('2026-11-10', PageViewSection::Marketing, StatDimension::Total)?->views)->toBe(1);
});

it('puts a view on the day it happened in the app timezone', function (): void {
    PageView::factory()->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-18 23:59:59')]);
    PageView::factory()->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-19 00:00:00')]);
    PageView::factory()->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-19 23:59:59')]);
    PageView::factory()->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-11-20 00:00:00')]);

    artisan('analytics:rollup')->assertSuccessful();

    expect(analyticsStat('2026-11-19', PageViewSection::Marketing, StatDimension::Total)?->views)->toBe(2);
});

it('prunes anonymous rows after 30 days and linked rows after 90', function (): void {
    $user = User::factory()->create();

    $keptAnonymous = PageView::factory()->anonymous()->create([PageView::VIEWED_AT => now()->subDays(29)]);
    PageView::factory()->anonymous()->create([PageView::VIEWED_AT => now()->subDays(31)]);
    $keptLinked = PageView::factory()->linked($user)->create([PageView::VIEWED_AT => now()->subDays(89)]);
    PageView::factory()->linked($user)->create([PageView::VIEWED_AT => now()->subDays(91)]);

    artisan('model:prune', ['--model' => [PageView::class]])->assertSuccessful();

    expect(PageView::query()->pluck(PageView::ID)->sort()->values()->all())
        ->toBe([$keptAnonymous->id, $keptLinked->id]);
});

it('refuses to roll up a day whose raw rows may be pruned, and keeps its totals', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 00:20:00'));
    PageView::factory()->count(3)->create([PageView::VIEWED_AT => CarbonImmutable::parse('2026-10-15 08:00:00')]);
    artisan('analytics:rollup')->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00'));
    artisan('model:prune', ['--model' => [PageView::class]]);

    artisan('analytics:rollup', ['--date' => '2026-10-15'])->assertFailed();
    artisan('analytics:rollup', ['--date' => '2026-10-22'])->assertSuccessful();

    expect(analyticsStat('2026-10-15', PageViewSection::Marketing, StatDimension::Total)?->views)->toBe(3);
});
