<?php declare(strict_types=1);

use App\Enums\ProbeFailure;
use App\Models\FailedShopPage;
use App\Models\User;
use App\Support\UrlNormalizer;

function failedShopPage(string $url, ProbeFailure $failure, string $lastFailedAt, ?string $reason = null, ?User $user = null): void
{
    FailedShopPage::query()->create([
        'url' => $url,
        'url_hash' => UrlNormalizer::hash($url),
        'host' => UrlNormalizer::normalizeHost((string) parse_url($url, PHP_URL_HOST)),
        'failure' => $failure,
        'reason' => $reason,
        'user_id' => $user?->id,
        'times' => 2,
        'first_failed_at' => '2026-10-01 08:00:00',
        'last_failed_at' => $lastFailedAt,
    ]);
}

beforeEach(function (): void {
    failedShopPage('https://shop.test/p/beans', ProbeFailure::Blocked, '2026-10-20 10:00:00');
    failedShopPage('https://shop.test/p/rice', ProbeFailure::ExtractionFailed, '2026-10-22 10:00:00', 'jsonld_no_price', User::factory()->create(['email' => 'tried@example.test']));
    failedShopPage('https://other.test/p/tea', ProbeFailure::Blocked, '2026-10-21 10:00:00');
});

it('lists the newest tries first, up to the limit, says how many there are, and names no person', function (): void {
    $this->artisan('dipcatch:failed-shop-pages', ['--limit' => 2])
        ->expectsTable(['shop', 'page', 'failure', 'reason', 'tries', 'last try'], [
            ['shop.test', 'https://shop.test/p/rice', 'Extraction Failed', 'jsonld_no_price', 2, '2026-10-22 10:00:00'],
            ['other.test', 'https://other.test/p/tea', 'Blocked', '', 2, '2026-10-21 10:00:00'],
        ])
        ->expectsOutput('Showing 2 of 3.')
        ->doesntExpectOutputToContain('tried@example.test')
        ->assertSuccessful();
});

it('narrows the list to one host, with or without www, and one failure', function (): void {
    $this->artisan('dipcatch:failed-shop-pages', ['--host' => 'www.shop.test', '--failure' => 'blocked'])
        ->expectsTable(['shop', 'page', 'failure', 'reason', 'tries', 'last try'], [
            ['shop.test', 'https://shop.test/p/beans', 'Blocked', '', 2, '2026-10-20 10:00:00'],
        ])
        ->expectsOutput('Showing 1 of 1.')
        ->assertSuccessful();
});

it('refuses a failure that is never recorded', function (): void {
    $this->artisan('dipcatch:failed-shop-pages', ['--failure' => 'not_a_shop'])
        ->expectsOutputToContain('Unknown failure "not_a_shop"')
        ->assertFailed();
});
