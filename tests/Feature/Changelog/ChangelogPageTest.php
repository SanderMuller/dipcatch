<?php declare(strict_types=1);

use App\Models\User;
use App\Support\Changelog;
use App\Support\ChangelogEntry;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

test('guests cannot open the changelog', function (): void {
    $this->get(route('app.changelog'))
        ->assertRedirect(route('login'));
});

test('the changelog lists entries newest first with their category', function (): void {
    Config::set('changelog.entries', [
        ['date' => '2026-08-01', 'category' => 'fix', 'title' => 'Older fix', 'body' => 'Fixed a thing.'],
        ['date' => '2026-09-15', 'category' => 'shop', 'title' => 'Newer shop', 'body' => "First paragraph.\n\nSecond paragraph."],
        ['date' => '2026-09-01', 'category' => 'pro', 'title' => 'Middle Pro feature', 'body' => 'Pro does more.'],
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('app.changelog'))
        ->assertOk()
        ->assertSeeInOrder(['New shop', 'Newer shop', 'Middle Pro feature', 'Older fix'])
        ->assertSeeInOrder(['First paragraph.', 'Second paragraph.'])
        ->assertSee('15 September 2026');
});

test('the changelog groups entries from the same date under one heading', function (): void {
    Config::set('changelog.entries', [
        ['date' => '2026-09-15', 'category' => 'feature', 'title' => 'First of the day', 'body' => 'One.'],
        ['date' => '2026-09-01', 'category' => 'fix', 'title' => 'Earlier day', 'body' => 'Two.'],
        ['date' => '2026-09-15', 'category' => 'fix', 'title' => 'Second of the day', 'body' => 'Three.'],
    ]);

    $days = Changelog::byDate();

    expect($days)->toHaveCount(2)
        ->and(array_map(static fn (ChangelogEntry $entry): string => $entry->title, $days[0]['entries']))->toBe(['First of the day', 'Second of the day'])
        ->and($days[1]['date']->toDateString())->toBe('2026-09-01');

    $response = $this->actingAs(User::factory()->create())
        ->get(route('app.changelog'))
        ->assertOk()
        ->assertSeeInOrder(['15 September 2026', 'First of the day', 'Second of the day', '1 September 2026', 'Earlier day']);

    expect(substr_count((string) $response->getContent(), '15 September 2026'))->toBe(1);
});

test('the changelog escapes entry text', function (): void {
    Config::set('changelog.entries', [
        ['date' => '2026-09-15', 'category' => 'feature', 'title' => '<b>Bold</b>', 'body' => '<script>alert(1)</script>'],
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('app.changelog'))
        ->assertOk()
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;b&gt;Bold&lt;/b&gt;');
});

test('the changelog says so when it has no entries', function (): void {
    Config::set('changelog.entries', []);

    $this->actingAs(User::factory()->create())
        ->get(route('app.changelog'))
        ->assertOk()
        ->assertSee('Nothing here yet.');
});

test('the app menu links to the changelog', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee(route('app.changelog'));
});

test('an entry shows its link, its video and its screenshot', function (): void {
    Config::set('changelog.entries', [
        ['date' => '2026-09-15', 'category' => 'feature', 'title' => 'With a video', 'body' => 'B.', 'video' => 'best-buys-here', 'link' => ['route' => 'app.products.index', 'label' => 'Open your products']],
        ['date' => '2026-09-14', 'category' => 'feature', 'title' => 'With a screenshot', 'body' => 'B.', 'image' => ['src' => 'changelog/suggested-shops.png', 'alt' => 'The suggested shops card']],
    ]);

    [$width, $height] = getimagesize(public_path('changelog/suggested-shops.png')) ?: [0, 0];

    $response = $this->actingAs(User::factory()->create())
        ->get(route('app.changelog'))
        ->assertOk()
        ->assertSee(route('app.products.index'))
        ->assertSee('Open your products')
        ->assertSee(asset('changelog/best-buys-here.mp4'))
        ->assertSeeHtml('poster="' . asset('changelog/best-buys-here.jpg') . '"')
        ->assertSeeHtml('alt="The suggested shops card"')
        ->assertSeeHtml("width=\"{$width}\" height=\"{$height}\"");

    // The user starts the video, with sound.
    expect((string) $response->getContent())->not->toContain('autoplay');
});

test('a link to a public page loads it in full, and an app link navigates in place', function (): void {
    Config::set('changelog.entries', [
        ['date' => '2026-09-15', 'category' => 'shop', 'title' => 'Public', 'body' => 'B.', 'link' => ['route' => 'shops', 'label' => 'See all supported shops']],
        ['date' => '2026-09-14', 'category' => 'feature', 'title' => 'App', 'body' => 'B.', 'link' => ['route' => 'app.billing', 'label' => 'Open Plan & billing']],
    ]);

    [$public, $app] = Changelog::entries();

    expect($public->linkIsInApp())->toBeFalse()
        ->and($app->linkIsInApp())->toBeTrue();
});

test('every committed changelog entry is valid, and its link and media exist', function (): void {
    $entries = Changelog::entries();

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $entry) {
        expect($entry->paragraphs())->not->toBeEmpty();

        if ($entry->link !== null) {
            expect(Route::has($entry->link['route']))->toBeTrue("No route named {$entry->link['route']}");
        }

        if ($entry->video !== null) {
            expect(public_path("changelog/{$entry->video}.mp4"))->toBeFile()
                ->and(public_path("changelog/{$entry->video}.jpg"))->toBeFile();
        }

        if ($entry->image !== null) {
            expect(public_path($entry->image['src']))->toBeFile();
        }
    }
});

test('a malformed changelog entry fails loudly', function (array $row): void {
    Config::set('changelog.entries', [$row]);

    Changelog::entries();
})->with([
    'unknown category' => [['date' => '2026-09-15', 'category' => 'news', 'title' => 'T', 'body' => 'B']],
    'bad date' => [['date' => '15-09-2026', 'category' => 'fix', 'title' => 'T', 'body' => 'B']],
    'impossible date' => [['date' => '2026-02-30', 'category' => 'fix', 'title' => 'T', 'body' => 'B']],
    'empty body' => [['date' => '2026-09-15', 'category' => 'fix', 'title' => 'T', 'body' => ' ']],
    'a video and an image' => [['date' => '2026-09-15', 'category' => 'feature', 'title' => 'T', 'body' => 'B', 'video' => 'a', 'image' => ['src' => 'a.png', 'alt' => 'A']]],
    'a video path, not a slug' => [['date' => '2026-09-15', 'category' => 'feature', 'title' => 'T', 'body' => 'B', 'video' => '../a.mp4']],
    'a link without a label' => [['date' => '2026-09-15', 'category' => 'feature', 'title' => 'T', 'body' => 'B', 'link' => ['route' => 'app.dashboard']]],
    'an image without alt text' => [['date' => '2026-09-15', 'category' => 'feature', 'title' => 'T', 'body' => 'B', 'image' => ['src' => 'a.png', 'alt' => '']]],
])->throws(InvalidArgumentException::class);
