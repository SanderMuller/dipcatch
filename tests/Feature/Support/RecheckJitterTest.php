<?php declare(strict_types=1);

use App\Support\RecheckJitter;

test('a window inside the SQS ceiling is used as configured', function (): void {
    config()->set('dipcatch.recheck.jitter_minutes', 10);

    expect(RecheckJitter::maxSeconds())->toBe(600);
});

test('a window wider than the SQS ceiling is capped at 900 seconds', function (): void {
    config()->set('dipcatch.recheck.jitter_minutes', 30);

    expect(RecheckJitter::maxSeconds())->toBe(900);
});

test('a negative window collapses to no delay rather than a negative one', function (): void {
    config()->set('dipcatch.recheck.jitter_minutes', -5);

    expect(RecheckJitter::maxSeconds())->toBe(0);
});
