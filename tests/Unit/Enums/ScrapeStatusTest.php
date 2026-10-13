<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;

test('ScrapeStatus backing values stay stable, because rows already hold them', function (): void {
    expect(ScrapeStatus::Ok->value)->toBe('ok')
        ->and(ScrapeStatus::EmptyMatch->value)->toBe('empty_match')
        ->and(ScrapeStatus::HttpError->value)->toBe('http_error')
        ->and(ScrapeStatus::ParseError->value)->toBe('parse_error')
        ->and(ScrapeStatus::RobotsBlocked->value)->toBe('robots_blocked')
        ->and(ScrapeStatus::NeedsJs->value)->toBe('needs_js');
});
